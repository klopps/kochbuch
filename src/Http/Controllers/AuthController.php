<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\MailService;

final class AuthController extends BaseController
{
    private const RESET_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AuthService $authService,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly string $appUrl,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new ValidationException('Username and password are required.', 'auth.missing_credentials');
        }

        return $this->json($response, ['data' => $this->authService->login($username, $password)]);
    }

    public function me(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $user = $this->users->findById((int) $auth['sub']);
        unset($user['password']);

        return $this->json($response, ['data' => $user]);
    }

    /**
     * Public token redemption for both the invite flow and the forgot-
     * password flow - see AuthService::setNewPassword() and
     * templates/set-password.php.
     */
    public function setPassword(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($token === '' || $password === '') {
            throw new ValidationException('Token and password are required.', 'auth.missing_token_or_password');
        }

        return $this->json($response, ['data' => $this->authService->setNewPassword($token, $password)]);
    }

    /**
     * Public "forgot password" entry point - always returns the same
     * generic response whether or not the account exists, and swallows
     * mail-send failures, both deliberately (anti-enumeration, mirrors
     * YTAN's AuthController::forgotPassword()).
     */
    public function forgotPassword(Request $request, Response $response): Response
    {
        $body = $this->jsonBody($request);
        $usernameOrEmail = trim((string) ($body['username'] ?? ''));

        if ($usernameOrEmail !== '') {
            $user = $this->users->findByUsername($usernameOrEmail) ?? $this->users->findByEmail($usernameOrEmail);
            if ($user !== null) {
                $token = $this->users->createToken((int) $user['id'], 'reset', self::RESET_TTL_SECONDS);
                $link = rtrim($this->appUrl, '/') . '/set-password?token=' . $token;
                try {
                    $this->mail->sendPasswordReset($user['email'], $link);
                } catch (Throwable $e) {
                    error_log('Kochbuch: failed to send password reset email: ' . $e->getMessage());
                }
            }
        }

        return $this->json($response, ['data' => ['message' => 'If an account exists, a reset link has been sent.']]);
    }

    /**
     * Self-service password change for the currently logged-in user -
     * requires the current password. See UserController::setPassword() for
     * the admin-override variant that doesn't (mirrors YTAN's
     * AuthController::changePassword()).
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            throw new ValidationException('Current password and new password are required.', 'auth.current_new_password_required');
        }

        $this->authService->changePassword((int) $auth['sub'], $currentPassword, $newPassword);

        return $this->json($response, ['data' => ['message' => 'Password changed.']]);
    }
}
