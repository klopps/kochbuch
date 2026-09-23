<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kochbuch\Database\Connection;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\AuthService;

/**
 * validatePasswordFormat() is pure logic (no DB reads/writes), but
 * AuthService's constructor needs a UserRepository (which needs a PDO) -
 * constructing one is cheap (no query runs until a repository method is
 * actually called) and tests/bootstrap.php has already pointed the PDO at
 * the dedicated test database, so this stays safe to run alongside the
 * DB-backed integration tests.
 */
final class AuthServicePasswordValidationTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        $users = new UserRepository(Connection::fromEnv());
        $this->auth = new AuthService($users, 'unit-test-secret-at-least-32-bytes-long', 3600);
    }

    public function testAcceptsAStrongPassword(): void
    {
        $this->auth->validatePasswordFormat('Str0ng!Pass');
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testRejectsAPasswordShorterThan8Characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->validatePasswordFormat('Sh0rt!');
    }

    public function testRejectionCarriesTheAuthPasswordPolicyErrorCode(): void
    {
        try {
            $this->auth->validatePasswordFormat('Sh0rt!');
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.password_too_short', $e->getErrorCode());
        }
    }

    /**
     * Needs at least 3 of: lowercase, uppercase, digit, symbol - all-lowercase
     * letters is only 1 class even at sufficient length.
     */
    public function testRejectsAPasswordWithTooFewCharacterClasses(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->validatePasswordFormat('onlylowercase');
    }

    public function testAcceptsExactlyThreeCharacterClasses(): void
    {
        $this->auth->validatePasswordFormat('lowerUPPER123');
        $this->addToAssertionCount(1);
    }
}
