<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Exception\ValidationException;

/**
 * Issues and verifies the stateless JWTs the whole app authenticates with
 * (see CLAUDE.md: no PHP sessions - a long-lived JWT is what makes "Session
 * soll theoretisch unendlich bestehen bleiben" from todo.md simple). Every
 * place a token is minted funnels through the private issueToken() so the
 * claim shape never drifts between login and any future invite/reset flow.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly string $jwtSecret,
        private readonly int $ttlSeconds,
    ) {
        // firebase/php-jwt >=7 enforces this for HS256 (CVE-2025-45769) and
        // throws a DomainException deep inside JWT::encode() otherwise -
        // fail fast at boot with a clear message instead of a cryptic one
        // on the first login attempt.
        if (strlen($this->jwtSecret) * 8 < 256) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 bytes long.');
        }
    }

    /**
     * @return array{token:string, expires_at:int, user:array}
     */
    public function login(string $username, string $password): array
    {
        $user = $this->users->findByUsername($username) ?? $this->users->findByEmail($username);
        if ($user === null || $user['password'] === null || !password_verify($password, $user['password'])) {
            throw new UnauthorizedException('Invalid username or password.', 'auth.invalid_credentials');
        }

        unset($user['password']);

        return $this->issueToken($user);
    }

    private function issueToken(array $user): array
    {
        $expiresAt = time() + $this->ttlSeconds;
        $token = JWT::encode([
            'sub' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'is_admin' => (bool) $user['is_admin'],
            'iat' => time(),
            'exp' => $expiresAt,
        ], $this->jwtSecret, 'HS256');

        return ['token' => $token, 'expires_at' => $expiresAt, 'user' => $user];
    }

    public function verifyToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        return (array) $decoded;
    }

    /**
     * At least 8 characters, covering at least 3 of the 4 character classes
     * (lower/upper/digit/other) - same baseline rule as YTAN.
     */
    public function validatePasswordFormat(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters long.', 'auth.password_too_short');
        }

        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
        $classes += preg_match('/[^a-zA-Z0-9]/', $password) ? 1 : 0;

        if ($classes < 3) {
            throw new ValidationException(
                'Password must contain at least 3 of: lowercase, uppercase, digit, special character.',
                'auth.password_too_weak'
            );
        }
    }
}
