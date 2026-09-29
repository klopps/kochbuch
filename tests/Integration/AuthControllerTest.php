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
    private AuthService $auth;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        // Points at no real SMTP server - fine for these tests, since
        // AuthController::forgotPassword() swallows mail-send failures by
        // design (anti-enumeration), setPassword() never sends mail, and
        // updateProfile()'s email-change confirmation mail is likewise
        // swallowed by AuthController's own callers in these tests (only
        // reached when the test actually changes the email address).
        $mail = new MailService('smtp.invalid', 587, '', '', 'no-reply@example.test', 'Kochbuch', '');
        $this->auth = new AuthService($this->users, 'integration-test-secret-at-least-32-bytes', 3600, $mail, 'https://kochbuch.example.test');
        $this->controller = new AuthController($this->auth, $this->users, $mail, 'https://kochbuch.example.test');
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

    public function testLoginRejectsADeactivatedUser(): void
    {
        $this->createUser([
            'username' => 'chef',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
            'is_active' => 0,
        ]);

        try {
            $this->controller->login(
                $this->request('POST', '/api/v1/auth/login', jsonBody: ['username' => 'chef', 'password' => 'Str0ng!Pass']),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.account_deactivated', $e->getErrorCode());
        }
    }

    /**
     * todo.md "Deactivating users" - a session is meant to live nearly
     * forever (long JWT TTL), so deactivation has to be enforced on every
     * request, not just at the next login. See AuthService::verifyToken().
     */
    public function testVerifyTokenRevokesAnAlreadyIssuedTokenOnceTheUserIsDeactivated(): void
    {
        $userId = $this->createUser([
            'username' => 'chef',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
        ]);
        $token = $this->auth->login('chef', 'Str0ng!Pass')['token'];

        $this->assertNotNull($this->auth->verifyToken($token));

        $this->users->update($userId, ['is_active' => false]);

        $this->assertNull($this->auth->verifyToken($token));
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

    public function testChangePasswordUpdatesThePasswordWhenCurrentPasswordMatches(): void
    {
        $userId = $this->createUser(['username' => 'chef', 'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        $response = $this->controller->changePassword(
            $this->request('PUT', '/api/v1/auth/password', authPayload: $this->authPayload($userId), jsonBody: [
                'current_password' => 'Str0ng!Pass',
                'new_password' => 'NewStr0ng!Pass',
            ]),
            $this->response()
        );

        $this->assertSame(200, $this->decode($response)['status']);
        $updated = $this->users->findById($userId);
        $this->assertTrue(password_verify('NewStr0ng!Pass', $updated['password']));
    }

    public function testChangePasswordRejectsAnIncorrectCurrentPassword(): void
    {
        $userId = $this->createUser(['password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        try {
            $this->controller->changePassword(
                $this->request('PUT', '/api/v1/auth/password', authPayload: $this->authPayload($userId), jsonBody: [
                    'current_password' => 'wrong',
                    'new_password' => 'NewStr0ng!Pass',
                ]),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.current_password_incorrect', $e->getErrorCode());
        }
    }

    public function testChangePasswordRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->changePassword(
            $this->request('PUT', '/api/v1/auth/password', jsonBody: ['current_password' => 'a', 'new_password' => 'b']),
            $this->response()
        );
    }

    public function testChangePasswordRejectsAWeakNewPassword(): void
    {
        $userId = $this->createUser(['password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        $this->expectException(ValidationException::class);

        $this->controller->changePassword(
            $this->request('PUT', '/api/v1/auth/password', authPayload: $this->authPayload($userId), jsonBody: [
                'current_password' => 'Str0ng!Pass',
                'new_password' => 'weak',
            ]),
            $this->response()
        );
    }

    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Anna',
            'lastname' => 'Chef',
            'username' => 'anna',
            'email' => 'anna@example.test',
            'current_password' => 'Str0ng!Pass',
        ], $overrides);
    }

    public function testUpdateProfileAppliesFirstnameLastnameAndUsernameImmediately(): void
    {
        $userId = $this->createUser([
            'username' => 'chef',
            'email' => 'chef@example.test',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
        ]);

        $response = $this->controller->updateProfile(
            $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['email' => 'chef@example.test'])),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['token']);
        $this->assertFalse($result['data']['email_change_pending']);
        $this->assertSame('anna', $result['data']['user']['username']);
        $this->assertSame('Anna', $result['data']['user']['firstname']);
        $this->assertSame('Chef', $result['data']['user']['lastname']);
        // Email was unchanged in this request, so it applied directly with
        // no pending token - unlike a real change (see the next test).
        $this->assertSame('chef@example.test', $result['data']['user']['email']);
    }

    public function testUpdateProfileQueuesAnEmailChangeInsteadOfApplyingItImmediately(): void
    {
        $userId = $this->createUser([
            'username' => 'chef',
            'email' => 'chef@example.test',
            'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT),
        ]);

        $response = $this->controller->updateProfile(
            $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['email' => 'new-address@example.test'])),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['email_change_pending']);
        $this->assertSame('new-address@example.test', $result['data']['pending_email']);
        // The database row keeps the old, confirmed address until the link is redeemed.
        $this->assertSame('chef@example.test', $result['data']['user']['email']);

        $stmt = $this->pdo->prepare("SELECT * FROM user_token WHERE user_id = ? AND purpose = 'email_change'");
        $stmt->execute([$userId]);
        $tokenRow = $stmt->fetch();
        $this->assertNotFalse($tokenRow);
        $this->assertSame('new-address@example.test', $tokenRow['payload']);
    }

    public function testUpdateProfileRejectsAnIncorrectCurrentPassword(): void
    {
        $userId = $this->createUser(['password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        try {
            $this->controller->updateProfile(
                $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['current_password' => 'wrong'])),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.current_password_incorrect', $e->getErrorCode());
        }
    }

    public function testUpdateProfileRejectsADuplicateUsername(): void
    {
        $this->createUser(['username' => 'taken', 'email' => 'taken@example.test']);
        $userId = $this->createUser(['username' => 'chef', 'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        try {
            $this->controller->updateProfile(
                $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['username' => 'taken'])),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.username_in_use', $e->getErrorCode());
        }
    }

    public function testUpdateProfileRejectsADuplicateEmail(): void
    {
        $this->createUser(['username' => 'other', 'email' => 'taken@example.test']);
        $userId = $this->createUser(['username' => 'chef', 'password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        try {
            $this->controller->updateProfile(
                $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['email' => 'taken@example.test'])),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.email_in_use', $e->getErrorCode());
        }
    }

    public function testUpdateProfileRequiresAllFields(): void
    {
        $userId = $this->createUser(['password' => password_hash('Str0ng!Pass', PASSWORD_DEFAULT)]);

        try {
            $this->controller->updateProfile(
                $this->request('PUT', '/api/v1/auth/profile', authPayload: $this->authPayload($userId), jsonBody: $this->profilePayload(['firstname' => ''])),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.profile_fields_required', $e->getErrorCode());
        }
    }

    public function testConfirmEmailChangeAppliesThePendingAddress(): void
    {
        $userId = $this->createUser(['username' => 'chef', 'email' => 'old@example.test']);
        $token = $this->users->createToken($userId, 'email_change', 3600, 'new-address@example.test');

        $response = $this->controller->confirmEmailChange(
            $this->request('POST', '/api/v1/auth/confirm-email-change', jsonBody: ['token' => $token]),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame(200, $result['status']);
        $this->assertSame('new-address@example.test', $result['data']['user']['email']);
        $this->assertNull($this->users->findValidToken($token));
    }

    public function testConfirmEmailChangeRejectsAnUnknownToken(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->confirmEmailChange(
            $this->request('POST', '/api/v1/auth/confirm-email-change', jsonBody: ['token' => 'does-not-exist']),
            $this->response()
        );
    }

    public function testCancelEmailChangeRemovesThePendingToken(): void
    {
        $userId = $this->createUser();
        $token = $this->users->createToken($userId, 'email_change', 3600, 'new-address@example.test');

        $response = $this->controller->cancelEmailChange(
            $this->request('DELETE', '/api/v1/auth/email-change', authPayload: $this->authPayload($userId)),
            $this->response()
        );

        $this->assertSame(200, $this->decode($response)['status']);
        $this->assertNull($this->users->findValidToken($token));
    }

    public function testMeIncludesAPendingEmailChange(): void
    {
        $userId = $this->createUser(['username' => 'chef', 'email' => 'old@example.test']);
        $this->users->createToken($userId, 'email_change', 3600, 'new-address@example.test');

        $response = $this->controller->me(
            $this->request('GET', '/api/v1/auth/me', authPayload: $this->authPayload($userId, ['username' => 'chef'])),
            $this->response()
        );

        $result = $this->decode($response);
        $this->assertSame('new-address@example.test', $result['data']['pending_email']);
    }
}
