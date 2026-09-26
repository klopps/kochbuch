<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\UserController;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\MailService;

/**
 * Calls UserController methods directly (see ControllerTestCase), same
 * pattern as AuthControllerTest. MailService points at no real SMTP server
 * - fine here since every mail-sending path (issueLinkAndMail()) swallows
 * send failures and still returns the invite/reset link.
 */
final class UserControllerTest extends ControllerTestCase
{
    private UserController $controller;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $auth = new AuthService($this->users, 'integration-test-secret-at-least-32-bytes', 3600);
        $mail = new MailService('smtp.invalid', 587, '', '', 'no-reply@example.test', 'Kochbuch', '');
        $this->controller = new UserController($this->users, $auth, $mail, 'https://kochbuch.example.test');
    }

    public function testIndexRequiresAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $userId = $this->createUser();
        $this->controller->index($this->request('GET', '/api/v1/users', authPayload: $this->authPayload($userId)), $this->response());
    }

    public function testIndexListsAllUsersWithoutPasswordHashes(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $this->createUser(['username' => 'chef', 'password' => password_hash('irrelevant', PASSWORD_DEFAULT)]);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/users', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertGreaterThanOrEqual(2, count($result['data']));
        foreach ($result['data'] as $user) {
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    public function testCreateInvitesAUserWithoutAPasswordAndReturnsTheInviteLink(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $result = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/users', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'username' => 'newchef',
                'email' => 'newchef@example.test',
                'is_admin' => false,
            ]),
            $this->response()
        ));

        $this->assertSame(201, $result['status']);
        $this->assertSame('newchef', $result['data']['user']['username']);
        $this->assertArrayNotHasKey('password', $result['data']['user']);
        $this->assertStringContainsString('/set-password?token=', $result['data']['invite_link']);

        $created = $this->users->findByUsername('newchef');
        $this->assertNull($created['password']);
    }

    public function testCreateRejectsADuplicateUsername(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $this->createUser(['username' => 'existing']);

        $this->expectException(ValidationException::class);
        $this->controller->create(
            $this->request('POST', '/api/v1/users', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'username' => 'existing',
                'email' => 'other@example.test',
            ]),
            $this->response()
        );
    }

    public function testUpdateMergesPartialDataOntoTheExistingRow(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $targetId = $this->createUser(['username' => 'chef', 'email' => 'chef@example.test']);

        $result = $this->decode($this->controller->update(
            $this->request('PUT', "/api/v1/users/$targetId", authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'is_admin' => true,
            ]),
            $this->response(),
            ['id' => (string) $targetId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertTrue((bool) $result['data']['is_admin']);
        // Untouched fields survive the partial update.
        $this->assertSame('chef', $result['data']['username']);
        $this->assertSame('chef@example.test', $result['data']['email']);
    }

    public function testDeleteRemovesTheUser(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $targetId = $this->createUser();

        $result = $this->decode($this->controller->delete(
            $this->request('DELETE', "/api/v1/users/$targetId", authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response(),
            ['id' => (string) $targetId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertNull($this->users->findById($targetId));
    }

    public function testDeleteRefusesToDeleteYourOwnAccount(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $this->expectException(ValidationException::class);
        $this->controller->delete(
            $this->request('DELETE', "/api/v1/users/$adminId", authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response(),
            ['id' => (string) $adminId]
        );
    }

    public function testDeleteOfAnUnknownUserIsNotFound(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $this->expectException(NotFoundException::class);
        $this->controller->delete(
            $this->request('DELETE', '/api/v1/users/999999', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response(),
            ['id' => '999999']
        );
    }

    public function testSetPasswordOverridesAUsersPasswordDirectly(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $targetId = $this->createUser(['username' => 'chef']);

        $result = $this->decode($this->controller->setPassword(
            $this->request('PUT', "/api/v1/users/$targetId/password", authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'password' => 'Str0ng!Pass',
            ]),
            $this->response(),
            ['id' => (string) $targetId]
        ));

        $this->assertSame(200, $result['status']);
        $updated = $this->users->findById($targetId);
        $this->assertTrue(password_verify('Str0ng!Pass', $updated['password']));
    }

    public function testSendResetEmailCreatesAResetTokenAndReturnsTheLink(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $targetId = $this->createUser(['username' => 'chef', 'email' => 'chef@example.test']);

        $result = $this->decode($this->controller->sendResetEmail(
            $this->request('POST', "/api/v1/users/$targetId/send-reset", authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response(),
            ['id' => (string) $targetId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('/set-password?token=', $result['data']['reset_link']);
    }
}
