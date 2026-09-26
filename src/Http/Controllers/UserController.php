<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\MailService;

/**
 * Admin user management (todo.md "Admin-Oberfläche" -> Benutzerverwaltung),
 * mirroring YTAN's UserController - every method starts with
 * requireAdmin(). Kochbuch only has the one `is_admin` right (no YTAN-style
 * tour_* rights columns), so there's no per-right filter apparatus here.
 */
final class UserController extends BaseController
{
    private const INVITE_TTL_SECONDS = 7 * 24 * 3600;
    private const RESET_TTL_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthService $authService,
        private readonly MailService $mail,
        private readonly string $appUrl,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $users = array_map(static function (array $user) {
            unset($user['password']);

            return $user;
        }, $this->users->findAll());

        return $this->json($response, ['data' => $users]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $user = $this->findOrFail((int) $args['id']);
        unset($user['password']);

        return $this->json($response, ['data' => $user]);
    }

    /**
     * Invite-only creation - there's no password field in the request at
     * all, matching YTAN's admin user form. The invite link is always
     * returned alongside the user (not just emailed), so an admin can copy
     * it manually if SMTP isn't configured or delivery fails.
     */
    public function create(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $body = $this->jsonBody($request);
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $isAdmin = !empty($body['is_admin']);

        if ($username === '' || $email === '') {
            throw new ValidationException('Username and email are required.', 'user.username_email_required');
        }

        $userId = $this->users->create($username, $email, $isAdmin);
        $user = $this->users->findById($userId);
        unset($user['password']);

        $link = $this->issueLinkAndMail($userId, 'invite', self::INVITE_TTL_SECONDS, fn (string $link) => $this->mail->sendInvite($email, $username, $link));

        return $this->json($response, ['data' => ['user' => $user, 'invite_link' => $link]], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $id = (int) $args['id'];
        $this->findOrFail($id);

        $body = $this->jsonBody($request);
        $data = [];
        if (array_key_exists('username', $body)) {
            $data['username'] = trim((string) $body['username']);
        }
        if (array_key_exists('email', $body)) {
            $data['email'] = trim((string) $body['email']);
        }
        if (array_key_exists('is_admin', $body)) {
            $data['is_admin'] = !empty($body['is_admin']);
        }
        if (array_key_exists('preferred_locale', $body)) {
            $data['preferred_locale'] = (string) $body['preferred_locale'];
        }

        $this->users->update($id, $data);
        $user = $this->users->findById($id);
        unset($user['password']);

        return $this->json($response, ['data' => $user]);
    }

    /**
     * Hard delete (no soft-deactivate flag, matching YTAN) with a
     * self-delete guard - an admin can't lock themselves out by deleting
     * their own account.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAdmin($request);
        $id = (int) $args['id'];

        if ($id === (int) $auth['sub']) {
            throw new ValidationException('You cannot delete your own account.', 'user.cannot_delete_self');
        }
        $this->findOrFail($id);
        $this->users->delete($id);

        return $this->json($response, ['data' => ['message' => 'User deleted.']]);
    }

    /**
     * Admin override - sets a user's password directly, no token/current-
     * password check (AuthService::adminSetPassword()).
     */
    public function setPassword(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $user = $this->findOrFail((int) $args['id']);

        $body = $this->jsonBody($request);
        $password = (string) ($body['password'] ?? '');
        $this->authService->adminSetPassword((int) $user['id'], $password);

        return $this->json($response, ['data' => ['message' => 'Password updated.']]);
    }

    /**
     * Admin-triggered password-reset email for one already-identified user
     * - no anti-enumeration masking needed here, unlike the public
     * forgot-password flow (AuthController::forgotPassword()).
     */
    public function sendResetEmail(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $user = $this->findOrFail((int) $args['id']);

        $link = $this->issueLinkAndMail((int) $user['id'], 'reset', self::RESET_TTL_SECONDS, fn (string $link) => $this->mail->sendPasswordReset($user['email'], $link));

        return $this->json($response, ['data' => ['reset_link' => $link]]);
    }

    private function findOrFail(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return $user;
    }

    /**
     * Mints a token, builds its /set-password link, and mails it via the
     * given callback - mail failures are logged and swallowed rather than
     * failing the request, since the returned link itself is always a
     * working fallback (see create()'s doc-comment).
     */
    private function issueLinkAndMail(int $userId, string $purpose, int $ttlSeconds, callable $mailer): string
    {
        $token = $this->users->createToken($userId, $purpose, $ttlSeconds);
        $link = rtrim($this->appUrl, '/') . '/set-password?token=' . $token;

        try {
            $mailer($link);
        } catch (Throwable $e) {
            error_log("Kochbuch: failed to send $purpose email: " . $e->getMessage());
        }

        return $link;
    }
}
