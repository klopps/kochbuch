<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\UnauthorizedException;

abstract class BaseController
{
    protected function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @return array{sub:int,username:string,is_admin:bool}
     */
    protected function requireAuthUser(Request $request): array
    {
        $auth = $request->getAttribute('auth');
        if ($auth === null) {
            throw new UnauthorizedException();
        }

        return $auth;
    }

    /**
     * @return array{sub:int,username:string,is_admin:bool}
     */
    protected function requireAdmin(Request $request): array
    {
        $auth = $this->requireAuthUser($request);
        if (!($auth['is_admin'] ?? false)) {
            throw new ForbiddenException('Admin access required.');
        }

        return $auth;
    }

    /**
     * 403s unless the caller owns the resource (e.g. a recipe) or is admin -
     * the "privat (nur der Ersteller) oder öffentlich" ownership check from
     * todo.md.
     */
    protected function assertOwnerOrAdmin(array $authUser, int $ownerId): void
    {
        if ((int) $authUser['sub'] !== $ownerId && !($authUser['is_admin'] ?? false)) {
            throw new ForbiddenException();
        }
    }

    /**
     * @return array<string,mixed> decoded JSON body
     */
    protected function jsonBody(Request $request): array
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
