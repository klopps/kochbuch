<?php

declare(strict_types=1);

namespace Kochbuch\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Kochbuch\Database\Connection;

/**
 * Base class for tests that touch the database (tests/Integration/*).
 * Every test method runs inside a transaction that's rolled back in
 * tearDown(), so tests never need to clean up after themselves and never
 * see another test's leftover rows - regardless of test order.
 *
 * tests/bootstrap.php has already pointed DB_DATABASE at the dedicated
 * `<name>_test` database before this class (or Connection::fromEnv())
 * is ever touched - see bin/setup-test-db.php for how that database gets
 * its structure.
 */
abstract class TestCase extends BaseTestCase
{
    private static ?PDO $sharedPdo = null;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sharedPdo === null) {
            self::$sharedPdo = Connection::fromEnv();
        }
        $this->pdo = self::$sharedPdo;
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return int the new user's id
     */
    protected function createUser(array $overrides = []): int
    {
        $data = array_merge([
            'username' => 'user_' . bin2hex(random_bytes(4)),
            'email' => bin2hex(random_bytes(4)) . '@example.test',
            'password' => null,
            'is_admin' => 0,
            'preferred_locale' => 'de',
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user (username, email, password, is_admin, preferred_locale, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $data['username'],
            $data['email'],
            $data['password'],
            $data['is_admin'],
            $data['preferred_locale'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Builds a minimal decoded-JWT-shaped auth payload, as
     * BaseController::requireAuthUser()/etc. expect on the "auth" request
     * attribute - for tests that call a controller method directly rather
     * than going through AuthMiddleware.
     */
    protected function authPayload(int $userId, array $overrides = []): array
    {
        return array_merge([
            'sub' => $userId,
            'username' => 'user' . $userId,
            'email' => 'user' . $userId . '@example.test',
            'is_admin' => false,
        ], $overrides);
    }
}
