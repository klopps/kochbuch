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
        // Defaulted (not required) so tests/Unit/AuthServicePasswordValidationTest.php's
        // 3-arg construction keeps working - only updateProfile()'s email-change
        // path actually sends mail, same "no DI container, default-construct
        // a service" convention as e.g. RecipeController's BringService.
        private readonly MailService $mail = new MailService('', 587, '', '', 'no-reply@example.test', 'Kochbuch', ''),
        private readonly string $appUrl = '',
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
        if (!$user['is_active']) {
            throw new UnauthorizedException('This account has been deactivated.', 'auth.account_deactivated');
        }

        unset($user['password']);

        return $this->issueToken($user);
    }

    /**
     * Redeems an invite or password-reset token (same code path for both -
     * they only differ in who mints the token and its TTL, see
     * UserController::create()/sendResetEmail() and
     * AuthController::forgotPassword()): validates the token, sets the new
     * password, marks the token used, and immediately logs the user in via
     * the same issueToken() as login() - mirrors YTAN's
     * AuthService::setNewPassword().
     *
     * @return array{token:string, expires_at:int, user:array}
     */
    public function setNewPassword(string $rawToken, string $newPassword): array
    {
        $tokenRow = $this->users->findValidToken($rawToken);
        if ($tokenRow === null) {
            throw new ValidationException('This link is invalid or has expired.', 'auth.link_expired');
        }

        $this->validatePasswordFormat($newPassword);

        $user = $this->users->findById((int) $tokenRow['user_id']);
        $this->users->updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));
        $this->users->consumeToken($rawToken);

        unset($user['password']);

        return $this->issueToken($user);
    }

    /**
     * Admin override (UserController::setPassword()) - no token, no
     * current-password check, and doesn't mint a login token for the admin
     * (they already have their own session).
     */
    public function adminSetPassword(int $userId, string $newPassword): void
    {
        $this->validatePasswordFormat($newPassword);
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * Self-service password change for a logged-in user - requires proof of
     * the current password, unlike adminSetPassword() above (mirrors
     * YTAN's AuthService::changePassword()).
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['password'] === null || !password_verify($currentPassword, $user['password'])) {
            throw new UnauthorizedException('Current password is incorrect.', 'auth.current_password_incorrect');
        }

        $this->validatePasswordFormat($newPassword);
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * Self-service profile update for a logged-in user - requires proof of
     * the current password, same as changePassword() above (mirrors YTAN's
     * AuthService::updateProfile(), extended with username - see
     * UserRepository::updateProfile()'s doc-comment). firstname/lastname/
     * username take effect immediately. An email change does not: since
     * email is also the account's recovery address, it only takes effect
     * once the user clicks the confirmation link mailed to the *new*
     * address (see confirmEmailChange() below) - until then user.email in
     * the database, and in the JWT this call re-issues, stays unchanged.
     */
    public function updateProfile(int $userId, string $firstname, string $lastname, string $username, string $email, string $currentPassword): array
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['password'] === null || !password_verify($currentPassword, $user['password'])) {
            throw new UnauthorizedException('Current password is incorrect.', 'auth.current_password_incorrect');
        }

        $user = $this->users->updateProfile($userId, $firstname, $lastname, $username);

        $emailChangePending = false;
        $pendingEmail = null;

        if (strcasecmp($email, (string) $user['email']) !== 0) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ValidationException('Please enter a valid email address.', 'auth.invalid_email');
            }

            $existing = $this->users->findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== $userId) {
                throw new ValidationException('This email address is already in use.', 'auth.email_in_use');
            }

            $token = $this->users->createToken($userId, 'email_change', 3600, $email);
            $link = rtrim($this->appUrl, '/') . '/confirm-email?token=' . $token;
            try {
                $this->mail->sendEmailChangeConfirmation($email, $link);
            } catch (\Throwable $e) {
                // Swallowed deliberately, same as AuthController::forgotPassword()
                // and UserController::issueLinkAndMail() - a mail-delivery
                // failure here shouldn't fail firstname/lastname/username,
                // which already got saved above. The token still exists;
                // the user just needs to re-submit their email to get a
                // fresh confirmation link if this one never arrives.
                error_log('Kochbuch: failed to send email-change confirmation: ' . $e->getMessage());
            }

            $emailChangePending = true;
            $pendingEmail = $email;
        }

        unset($user['password']);
        $issued = $this->issueToken($user);
        $issued['email_change_pending'] = $emailChangePending;
        $issued['pending_email'] = $pendingEmail;

        return $issued;
    }

    /**
     * Cancels a pending email-change request, if any, without touching the
     * current (unconfirmed) address.
     */
    public function cancelEmailChange(int $userId): void
    {
        $this->users->deletePendingTokens($userId, 'email_change');
    }

    /**
     * Redeems an emailed email-change confirmation link: applies the new
     * address carried in the token's payload, consumes the token, and
     * re-issues a fresh JWT reflecting it - mirrors setNewPassword() above
     * and YTAN's AuthService::confirmEmailChange().
     */
    public function confirmEmailChange(string $rawToken): array
    {
        $tokenRow = $this->users->findValidToken($rawToken);
        if ($tokenRow === null || $tokenRow['purpose'] !== 'email_change') {
            throw new ValidationException('This link is invalid or has expired.', 'auth.link_expired');
        }

        $newEmail = (string) $tokenRow['payload'];

        $existing = $this->users->findByEmail($newEmail);
        if ($existing !== null && (int) $existing['id'] !== (int) $tokenRow['user_id']) {
            throw new ValidationException('This email address is already in use.', 'auth.email_in_use');
        }

        $user = $this->users->updateEmail((int) $tokenRow['user_id'], $newEmail);
        $this->users->consumeToken($rawToken);

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
            'firstname' => $user['firstname'] ?? null,
            'lastname' => $user['lastname'] ?? null,
            'is_admin' => (bool) $user['is_admin'],
            'iat' => time(),
            'exp' => $expiresAt,
        ], $this->jwtSecret, 'HS256');

        return ['token' => $token, 'expires_at' => $expiresAt, 'user' => $user];
    }

    /**
     * Besides decoding the JWT itself, this also re-checks the account's
     * current is_active flag against the database on every request (todo.md
     * "Deactivating users") - the JWT's own claims are trusted for identity/
     * rights (see issueToken()), but a session is meant to live "theoretisch
     * unendlich" (todo.md "Allgemein"), so a stale-but-unexpired token is the
     * only way an admin deactivating a user could actually cut off access
     * immediately rather than merely blocking their *next* login. A user
     * deleted or deactivated after the token was issued is treated the same
     * as no token at all.
     */
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

        $payload = (array) $decoded;

        $user = $this->users->findById((int) ($payload['sub'] ?? 0));
        if ($user === null || !$user['is_active']) {
            return null;
        }

        return $payload;
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
