<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Http\Controllers\AuthController;
use Kochbuch\Service\AuthService;

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
        $this->controller = new AuthController($auth, $this->users);
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
}
