<?php

declare(strict_types=1);

namespace Kochbuch\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;
use Kochbuch\Service\AuthService;

/**
 * Decodes the Authorization: Bearer <jwt> header, if present, and attaches
 * the payload as the "auth" request attribute (null if absent/invalid).
 * Does NOT reject requests itself - routes that require a logged-in user
 * call BaseController::requireAuthUser() themselves, so public and
 * optionally-authenticated endpoints (e.g. a public recipe) can share this
 * same middleware.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        $token = null;

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        $payload = $this->authService->verifyToken($token);

        return $handler->handle($request->withAttribute('auth', $payload));
    }
}
