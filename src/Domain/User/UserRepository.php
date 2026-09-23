<?php

declare(strict_types=1);

namespace Kochbuch\Domain\User;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $username, string $email, string $passwordHash, string $preferredLocale, bool $isAdmin = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user (username, email, password, preferred_locale, is_admin, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$username, $email, $passwordHash, $preferredLocale, $isAdmin ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE user SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }
}
