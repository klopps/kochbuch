<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Kochbuch\Tests\TestCase;

/**
 * Base for tests that call a *Controller method directly (bypassing Slim's
 * router and AuthMiddleware entirely) - close enough to a real request for
 * a controller's own logic (permission checks, validation, response shape),
 * without needing a running HTTP server.
 *
 * Since these calls skip Slim's error middleware, an ApiException a
 * controller throws (Forbidden/Validation/NotFound/...) propagates
 * directly to the test - exactly what expectException() wants.
 */
abstract class ControllerTestCase extends TestCase
{
    protected function request(
        string $method,
        string $uri,
        ?array $authPayload = null,
        ?array $jsonBody = null,
        array $queryParams = []
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        if ($authPayload !== null) {
            $request = $request->withAttribute('auth', $authPayload);
        }
        if ($jsonBody !== null) {
            $stream = (new StreamFactory())->createStream(json_encode($jsonBody));
            $request = $request->withBody($stream);
        }
        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }

        return $request;
    }

    protected function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array{status:int, data:mixed, error:mixed}
     */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'status' => $response->getStatusCode(),
            'data' => $decoded['data'] ?? null,
            'error' => $decoded['error'] ?? null,
        ];
    }
}
