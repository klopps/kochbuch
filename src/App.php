<?php

declare(strict_types=1);

namespace Kochbuch;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Throwable;
use Kochbuch\Database\Connection;
use Kochbuch\Domain\Category\CategoryRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ApiException;
use Kochbuch\Http\Controllers\AuthController;
use Kochbuch\Http\Controllers\CategoryController;
use Kochbuch\Http\Controllers\RecipeController;
use Kochbuch\Http\Middleware\AuthMiddleware;
use Kochbuch\Http\Middleware\CorsMiddleware;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\RecipeImageService;
use Kochbuch\Service\Translator;

final class App
{
    public static function create(string $rootDir): SlimApp
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $pdo = Connection::fromEnv();

        $appName = $_ENV['APP_NAME'] ?? 'Kochbuch';

        $supportedLocales = array_map('trim', explode(',', $_ENV['SUPPORTED_LOCALES'] ?? 'de,en'));
        $locale = Translator::resolveLocale(
            $supportedLocales,
            $_COOKIE['settings'] ?? null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        );
        $translator = new Translator($rootDir . '/resources/i18n', $locale);

        $userRepository = new UserRepository($pdo);
        $authService = new AuthService(
            $userRepository,
            $_ENV['JWT_SECRET'] ?? 'insecure-dev-secret',
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 315360000)
        );
        $authController = new AuthController($authService, $userRepository);

        $recipeRepository = new RecipeRepository($pdo);
        $recipeImageService = new RecipeImageService($rootDir . '/storage/recipe-images');
        $recipeController = new RecipeController($recipeRepository, $recipeImageService);

        $categoryRepository = new CategoryRepository($pdo);
        $categoryController = new CategoryController($categoryRepository, $recipeRepository);

        $app = AppFactory::create();

        // Subdirectory-safe: derive the base path from the request rather
        // than hardcoding it, so this app can be served from e.g.
        // https://example.com/kochbuch/public/ as well as a vhost root.
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($basePath !== '' && $basePath !== '/') {
            $app->setBasePath($basePath);
        }
        $baseUrl = $basePath === '/' ? '' : $basePath;

        $app->addRoutingMiddleware();
        $app->add(new CorsMiddleware($_ENV['ALLOWED_ORIGINS'] ?? '*'));
        $app->add(new AuthMiddleware($authService));

        $displayErrors = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            function (Request $request, Throwable $exception) use ($app, $displayErrors) {
                $status = match (true) {
                    $exception instanceof ApiException => $exception->getStatusCode(),
                    $exception instanceof \Slim\Exception\HttpSpecializedException => $exception->getCode(),
                    default => 500,
                };
                $payload = ['error' => ['message' => $exception->getMessage()]];
                if ($exception instanceof ApiException && $exception->getErrorCode() !== null) {
                    $payload['error']['code'] = $exception->getErrorCode();
                }
                if ($displayErrors && $status === 500) {
                    $payload['error']['trace'] = explode("\n", $exception->getTraceAsString());
                }

                $response = $app->getResponseFactory()->createResponse($status);
                $response->getBody()->write(json_encode($payload));

                return $response->withHeader('Content-Type', 'application/json');
            }
        );

        $app->get('/api/v1/health', fn (Request $req, Response $res) => self::jsonOk($res, ['status' => 'ok']));

        $app->post('/api/v1/auth/login', [$authController, 'login']);
        $app->get('/api/v1/auth/me', [$authController, 'me']);

        $app->get('/api/v1/recipes', [$recipeController, 'index']);
        $app->post('/api/v1/recipes', [$recipeController, 'create']);
        $app->get('/api/v1/recipes/{id}', [$recipeController, 'show']);
        $app->put('/api/v1/recipes/{id}', [$recipeController, 'update']);
        $app->delete('/api/v1/recipes/{id}', [$recipeController, 'delete']);
        $app->get('/api/v1/recipes/{id}/export/{format}', [$recipeController, 'export']);
        $app->post('/api/v1/recipes/{id}/images', [$recipeController, 'uploadImage']);
        $app->get('/api/v1/recipes/{id}/images/{imageId}', [$recipeController, 'serveImage']);
        $app->delete('/api/v1/recipes/{id}/images/{imageId}', [$recipeController, 'deleteImage']);
        $app->put('/api/v1/recipes/{id}/primary-image', [$recipeController, 'setPrimaryImage']);

        $app->get('/api/v1/categories', [$categoryController, 'index']);
        $app->post('/api/v1/categories', [$categoryController, 'create']);
        $app->put('/api/v1/categories/{id}', [$categoryController, 'update']);
        $app->delete('/api/v1/categories/{id}', [$categoryController, 'delete']);
        $app->get('/api/v1/categories/{id}/recipes', [$categoryController, 'recipes']);
        $app->put('/api/v1/categories/{id}/recipes/{recipeId}', [$categoryController, 'addRecipe']);
        $app->delete('/api/v1/categories/{id}/recipes/{recipeId}', [$categoryController, 'removeRecipe']);

        // Cache-Control: no-store on every server-rendered HTML page - it's
        // the one response the browser must always fetch fresh, or a stale
        // cached copy keeps pointing its <script src=".../foo.js?v=OLD_MTIME">
        // tags at an equally stale cached asset forever, defeating
        // App::assetVersion()'s whole point. "no-cache" alone isn't enough
        // here: it only forces revalidation via a conditional request, which
        // needs an ETag/Last-Modified response header to revalidate against -
        // this response sends neither, so some browsers fall back to serving
        // the cached copy outright instead. no-store has no such loophole.
        $app->get('/', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            require $rootDir . '/templates/app.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        $app->get('/imprint', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/imprint.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        $app->get('/privacy', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl) {
            ob_start();
            require $rootDir . '/templates/privacy.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });

        return $app;
    }

    private static function jsonOk(Response $response, mixed $data): Response
    {
        $response->getBody()->write(json_encode(['data' => $data]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Cache-busting query param for a vendored public/ asset, based on its
     * own file mtime - see templates/app.php's/imprint.php's/privacy.php's
     * ?v=... script/link tags.
     */
    public static function assetVersion(string $rootDir, string $relativePath): string
    {
        $file = $rootDir . '/public' . $relativePath;

        return (string) (is_file($file) ? filemtime($file) : time());
    }
}
