<?php

declare(strict_types=1);

namespace Kochbuch\Domain\User;

use PDO;
use PDOException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;

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

    /**
     * All users, ordered by username - the admin user list loads this once
     * and does its own search/filtering client-side (see
     * public/js/admin-user.js), same as YTAN's admin-user.js. Kochbuch has
     * only a handful of users at a time, unlike the recipe list, so there's
     * no need for server-side pagination here.
     *
     * @return array[]
     */
    public function findAll(): array
    {
        return $this->pdo->query('SELECT * FROM user ORDER BY username ASC')->fetchAll();
    }

    /**
     * Admin-invite creation (mirrors YTAN's UserRepository::create()) -
     * password stays NULL until the invited user redeems their invite token
     * via /set-password (see AuthService::setNewPassword()).
     */
    public function create(string $username, string $email, bool $isAdmin, string $preferredLocale = 'de'): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user (username, email, password, is_admin, preferred_locale, created_at)
                 VALUES (?, ?, NULL, ?, ?, NOW())'
            );
            $stmt->execute([$username, $email, $isAdmin ? 1 : 0, $preferredLocale]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('Username or email is already in use.', 'user.duplicate');
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Partial update, merged onto the existing row first so a form that
     * only sends a subset of fields can't blank out the others (mirrors
     * YTAN's UserRepository::update()).
     *
     * @param array{username?:string, email?:string, is_admin?:bool, preferred_locale?:string} $data
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new NotFoundException('User not found.');
        }
        $merged = array_merge($existing, $data);

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE user SET username = ?, email = ?, is_admin = ?, preferred_locale = ? WHERE id = ?'
            );
            $stmt->execute([
                $merged['username'],
                $merged['email'],
                $merged['is_admin'] ? 1 : 0,
                $merged['preferred_locale'],
                $id,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('Username or email is already in use.', 'user.duplicate');
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM user WHERE id = ?')->execute([$id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE user SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    /**
     * Mints a single-use token (invite/reset), invalidating any previous
     * unused token of the same purpose for this user first - mirrors YTAN's
     * UserRepository::createToken()/deletePendingTokens().
     */
    public function createToken(int $userId, string $purpose, int $ttlSeconds): string
    {
        $this->deletePendingTokens($userId, $purpose);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $this->pdo->prepare(
            'INSERT INTO user_token (user_id, token, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$userId, $token, $purpose, $expiresAt]);

        return $token;
    }

    public function deletePendingTokens(int $userId, string $purpose): void
    {
        $this->pdo->prepare('DELETE FROM user_token WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
            ->execute([$userId, $purpose]);
    }

    /**
     * A not-yet-used, not-yet-expired token row, or null - both invite
     * redemption and password reset check this before letting a new
     * password through (AuthService::setNewPassword()).
     */
    public function findValidToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_token WHERE token = ? AND used_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function consumeToken(string $token): void
    {
        $this->pdo->prepare('UPDATE user_token SET used_at = NOW() WHERE token = ?')->execute([$token]);
    }
}
