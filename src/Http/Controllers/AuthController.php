<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\AuthService;

final class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserRepository $users,
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
}
