<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\AuthController;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\MailService;

/**
 * Calls AuthController methods directly (see ControllerTestCase), so an
 * ApiException it throws propagates straight to the test - there's no
 * Slim error middleware in the loop to turn it into a JSON response the
 * way a real HTTP request would get. Repositories are built on $this->pdo
 * (the shared, transaction-wrapped connection from TestCase), not a fresh
 * Connection::fromEnv() - a second connection wouldn't see this test's
 * still-uncommitted INSERTs.
 */
final class AuthControllerTest extends ControllerTestCase
{
    private AuthController $controller;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $auth = new AuthService($this->users, 'integration-test-secret-at-least-32-bytes', 3600);
        // Points at no real SMTP server - fine for these tests, since
        // AuthController::forgotPassword() swallows mail-send failures by
        // design (anti-enumeration) and setPassword() never sends mail.
        $mail = new MailService('smtp.invalid', 587, '', '', 'no-reply@example.test', 'Kochbuch', '');
        $this->controller = new AuthController($auth, $this->users, $mail, 'https://kochbuch.example.test');
    }

    public function testLoginReturnsATokenForValidCredentials(): void
    {
        $this->createUser([
            'username' => 'chef',
            'email' => 'chef@example.test',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
        ]);

        $response = $this->controller->login(
            $this->request('POST', '/api/v1/auth/login', jsonBody: ['username' => 'chef', 'password' => 'Str0ng!Pass']),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertSame('chef', $result['data']['user']['username']);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->createUser([
            'username' => 'chef',
            'email' => 'chef@example.test',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
        ]);

        try {
            $this->controller->login(
                $this->request('POST', '/api/v1/auth/login', jsonBody: ['username' => 'chef', 'password' => 'wrong']),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.invalid_credentials', $e->getErrorCode());
        }
    }

    public function testMeReturnsTheAuthenticatedUser(): void
    {
        $userId = $this->createUser(['username' => 'chef', 'email' => 'chef@example.test']);

        $response = $this->controller->me(
            $this->request('GET', '/api/v1/auth/me', authPayload: $this->authPayload($userId, ['username' => 'chef'])),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertSame('chef', $result['data']['username']);
    }

    public function testMeRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->me($this->request('GET', '/api/v1/auth/me'), $this->response());
    }

    public function testSetPasswordRedeemsAValidTokenAndLogsIn(): void
    {
        $userId = $this->createUser(['username' => 'invitee', 'email' => 'invitee@example.test', 'password' => null]);
        $token = $this->users->createToken($userId, 'invite', 3600);

        $response = $this->controller->setPassword(
            $this->request('POST', '/api/v1/auth/set-password', jsonBody: ['token' => $token, 'password' => 'Str0ng!Pass']),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertSame('invitee', $result['data']['user']['username']);
        // The token is single-use - a second redemption attempt must fail.
        $this->assertNull($this->users->findValidToken($token));
    }

    public function testSetPasswordRejectsAnUnknownToken(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->setPassword(
            $this->request('POST', '/api/v1/auth/set-password', jsonBody: ['token' => 'does-not-exist', 'password' => 'Str0ng!Pass']),
            $this->response()
        );
    }

    public function testForgotPasswordCreatesAResetTokenForAnExistingUser(): void
    {
        $userId = $this->createUser(['username' => 'chef', 'email' => 'chef@example.test']);

        $response = $this->controller->forgotPassword(
            $this->request('POST', '/api/v1/auth/forgot-password', jsonBody: ['username' => 'chef']),
            $this->response()
        );

        $this->assertSame(200, $this->decode($response)['status']);
        $stmt = $this->pdo->prepare("SELECT * FROM user_token WHERE user_id = ? AND purpose = 'reset'");
        $stmt->execute([$userId]);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testForgotPasswordReturnsTheSameResponseForAnUnknownAccount(): void
    {
        $response = $this->controller->forgotPassword(
            $this->request('POST', '/api/v1/auth/forgot-password', jsonBody: ['username' => 'does-not-exist']),
            $this->response()
        );

        // Anti-enumeration: a 200 with the same generic message either way.
        $this->assertSame(200, $this->decode($response)['status']);
    }
}
