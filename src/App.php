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
use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\Setting\SettingRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ApiException;
use Kochbuch\Http\Controllers\AdminController;
use Kochbuch\Http\Controllers\GeminiQuotaController;
use Kochbuch\Http\Controllers\WatchlistController;
use Kochbuch\Http\Controllers\PhotoImportController;
use Kochbuch\Http\Controllers\AuthController;
use Kochbuch\Http\Controllers\CategoryController;
use Kochbuch\Http\Controllers\ChefkochImportController;
use Kochbuch\Http\Controllers\PlaceholderImageController;
use Kochbuch\Http\Controllers\RecipeController;
use Kochbuch\Http\Controllers\SettingsController;
use Kochbuch\Http\Controllers\TranslationController;
use Kochbuch\Http\Controllers\UserController;
use Kochbuch\Http\Middleware\AuthMiddleware;
use Kochbuch\Http\Middleware\CorsMiddleware;
use Kochbuch\Exception\TranslationKeyMismatchException;
use Kochbuch\Service\AuthService;
use Kochbuch\Service\BringService;
use Kochbuch\Service\ChefkochImportService;
use Kochbuch\Service\MailService;
use Kochbuch\Service\PlaceholderImageStorage;
use Kochbuch\Service\RecipeImageService;
use Kochbuch\Service\GeminiQuotaState;
use Kochbuch\Service\GeminiRecipeExtractor;
use Kochbuch\Service\RecipeOcrParser;
use Kochbuch\Service\Translator;
use Kochbuch\Service\TranslationRepository;
use Kochbuch\Service\TranslationUsageScanner;
use Kochbuch\Service\VisionOcrService;

final class App
{
    public static function create(string $rootDir): SlimApp
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $pdo = Connection::fromEnv();

        // Runtime-editable settings (admin "Einstellungen" page, todo.md
        // "Admin-Oberfläche") - unlike .env, these can change without a
        // redeploy, so App::create() (which runs fresh on every request,
        // there's no persistent app server here) always reads the current
        // DB values rather than caching them across requests.
        $settingRepository = new SettingRepository($pdo);
        $appName = $settingRepository->get('app_name', $_ENV['APP_NAME'] ?? 'Kochbuch');
        $recipePageSizes = $settingRepository->getIntList('recipe_page_sizes', [10, 20, 100]);
        $recipeDefaultPageSize = $settingRepository->getInt('recipe_default_page_size', $recipePageSizes[0] ?? 10);
        // Starting point for a browser with no "settings" cookie yet for
        // this preference (todo.md "Anpassungen von Schrift- und
        // Buttongrößen") - public/js/settings.js falls back to these via
        // window.KOCHBUCH_SETTINGS the same way it already does for the
        // recipe page-size default above.
        $defaultFontScaleDesktop = $settingRepository->getInt('default_font_scale_desktop', 100);
        $defaultFontScaleMobile = $settingRepository->getInt('default_font_scale_mobile', 80);

        $supportedLocales = array_map('trim', explode(',', $_ENV['SUPPORTED_LOCALES'] ?? 'de,en'));
        $locale = Translator::resolveLocale(
            $supportedLocales,
            $_COOKIE['settings'] ?? null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            $settingRepository->get('default_locale', 'de')
        );
        $translator = new Translator($rootDir . '/resources/i18n', $locale);

        $userRepository = new UserRepository($pdo);
        $appUrl = $_ENV['APP_URL'] ?? '';
        $mailService = new MailService(
            $_ENV['MAIL_HOST'] ?? '',
            (int) ($_ENV['MAIL_PORT'] ?? 587),
            $_ENV['MAIL_USERNAME'] ?? '',
            $_ENV['MAIL_PASSWORD'] ?? '',
            $_ENV['MAIL_FROM'] ?? 'no-reply@example.com',
            $appName,
            $_ENV['MAIL_ENCRYPTION'] ?? 'tls'
        );
        $authService = new AuthService(
            $userRepository,
            $_ENV['JWT_SECRET'] ?? 'insecure-dev-secret',
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 315360000),
            $mailService,
            $appUrl
        );
        $authController = new AuthController($authService, $userRepository, $mailService, $appUrl);
        $userController = new UserController($userRepository, $authService, $mailService, $appUrl);

        $placeholderImageRepository = new PlaceholderImageRepository($pdo);
        $placeholderImageStorage = new PlaceholderImageStorage($rootDir . '/public/storage/placeholder-images');
        $placeholderImageController = new PlaceholderImageController($placeholderImageRepository, $placeholderImageStorage);

        $recipeRepository = new RecipeRepository($pdo, $placeholderImageRepository, $recipePageSizes);
        $recipeImageService = new RecipeImageService($rootDir . '/storage/recipe-images');
        $bringService = new BringService();
        // todo.md "Importing Photos of Handwritten Recipes" - key lives in
        // .env like every other integration secret (JWT_SECRET, MAIL_*);
        // an empty key just makes every OCR request fail gracefully with
        // recipe.ocr_unavailable rather than crashing at boot.
        $visionOcrService = new VisionOcrService($_ENV['GOOGLE_VISION_API_KEY'] ?? '');
        // Preferred photo reader when a key is set (layout-independent, see
        // GeminiRecipeExtractor); without one ocr() keeps the Vision path.
        $geminiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $geminiModels = ($_ENV['GEMINI_MODEL'] ?? '') ?: GeminiRecipeExtractor::DEFAULT_MODELS;
        // Remembers rate-limited models across requests and today's
        // counters (shown on /admin/gemini) - outside the webroot, and
        // storage/ is never part of a deploy.
        $geminiQuotaState = new GeminiQuotaState($rootDir . '/storage/gemini-quota.json');
        $geminiExtractor = $geminiKey !== '' ? new GeminiRecipeExtractor($geminiKey, $geminiModels, quotaState: $geminiQuotaState) : null;
        $geminiQuotaController = new GeminiQuotaController($geminiQuotaState, GeminiRecipeExtractor::modelList($geminiModels), $geminiKey !== '');
        $recipeController = new RecipeController($recipeRepository, $recipeImageService, $recipeDefaultPageSize, $bringService, $appUrl, $visionOcrService, new RecipeOcrParser(), gemini: $geminiExtractor);
        // todo.md "Import aus Kochbuch von Chefkoch.de" - see
        // ChefkochImportService's own doc-comment for the endpoints this is
        // built on.
        $photoImportController = new PhotoImportController($recipeRepository, $userRepository, $geminiExtractor, $visionOcrService);
        $chefkochImportController = new ChefkochImportController($recipeRepository, $recipeImageService, new ChefkochImportService());

        $categoryRepository = new CategoryRepository($pdo);
        $categoryController = new CategoryController($categoryRepository, $recipeRepository);
        $watchlistController = new WatchlistController(new \Kochbuch\Domain\Watchlist\WatchlistRepository($pdo), $recipeRepository);

        $adminController = new AdminController($pdo);
        $settingsController = new SettingsController($settingRepository);
        $translationController = new TranslationController(
            new TranslationRepository($rootDir . '/resources/i18n'),
            new TranslationUsageScanner($rootDir)
        );

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
                if ($exception instanceof ApiException && $exception->getDetails() !== []) {
                    // e.g. retry_at for a Gemini rate limit - message/code win.
                    $payload['error'] += $exception->getDetails();
                }
                if ($exception instanceof TranslationKeyMismatchException) {
                    $payload['error'] = array_merge($payload['error'], $exception->getPayload());
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
        $app->post('/api/v1/auth/forgot-password', [$authController, 'forgotPassword']);
        $app->post('/api/v1/auth/set-password', [$authController, 'setPassword']);
        $app->put('/api/v1/auth/password', [$authController, 'changePassword']);
        $app->put('/api/v1/auth/profile', [$authController, 'updateProfile']);
        $app->delete('/api/v1/auth/email-change', [$authController, 'cancelEmailChange']);
        $app->post('/api/v1/auth/confirm-email-change', [$authController, 'confirmEmailChange']);

        $app->get('/api/v1/users', [$userController, 'index']);
        $app->post('/api/v1/users', [$userController, 'create']);
        $app->get('/api/v1/users/{id}', [$userController, 'show']);
        $app->put('/api/v1/users/{id}', [$userController, 'update']);
        $app->delete('/api/v1/users/{id}', [$userController, 'delete']);
        $app->put('/api/v1/users/{id}/password', [$userController, 'setPassword']);
        $app->post('/api/v1/users/{id}/send-reset', [$userController, 'sendResetEmail']);

        $app->get('/api/v1/admin/dashboard-stats', [$adminController, 'dashboardStats']);
        $app->get('/api/v1/admin/gemini-quota', [$geminiQuotaController, 'index']);
        $app->delete('/api/v1/admin/gemini-quota', [$geminiQuotaController, 'reset']);
        $app->get('/api/v1/admin/recipes', [$recipeController, 'adminIndex']);
        // todo.md "Deleting Recipes" - literal /deleted segment, no
        // collision with the {id}-parameterized routes below (different
        // path shape entirely).
        $app->get('/api/v1/admin/recipes/deleted', [$recipeController, 'adminListDeleted']);
        $app->put('/api/v1/admin/recipes/{id}/restore', [$recipeController, 'adminRestore']);
        $app->delete('/api/v1/admin/recipes/{id}/permanent', [$recipeController, 'adminPermanentlyDelete']);
        $app->get('/api/v1/admin/placeholder-images', [$placeholderImageController, 'index']);
        $app->post('/api/v1/admin/placeholder-images', [$placeholderImageController, 'create']);
        // POST, not PUT: PHP only populates $_FILES/$_POST (which
        // getUploadedFiles()/getParsedBody() read from) for actual POST
        // requests - a PUT with a multipart/form-data body would silently
        // see no fields/files at all.
        $app->post('/api/v1/admin/placeholder-images/{id}', [$placeholderImageController, 'update']);
        $app->delete('/api/v1/admin/placeholder-images/{id}', [$placeholderImageController, 'delete']);
        $app->get('/api/v1/admin/settings', [$settingsController, 'index']);
        $app->put('/api/v1/admin/settings', [$settingsController, 'update']);
        $app->post('/api/v1/admin/chefkoch-import/list', [$chefkochImportController, 'list']);
        $app->post('/api/v1/admin/chefkoch-import/import', [$chefkochImportController, 'import']);
        $app->post('/api/v1/admin/photo-import', [$photoImportController, 'import']);
        $app->get('/api/v1/translations', [$translationController, 'index']);
        $app->put('/api/v1/translations', [$translationController, 'update']);

        $app->get('/api/v1/recipes', [$recipeController, 'index']);
        $app->post('/api/v1/recipes', [$recipeController, 'create']);
        // Literal segments before the dynamic /api/v1/recipes/{id} route below.
        $app->post('/api/v1/recipes/ocr', [$recipeController, 'ocr']);
        $app->post('/api/v1/recipes/import-json', [$recipeController, 'importJson']);
        $app->get('/api/v1/recipes/{id}', [$recipeController, 'show']);
        $app->put('/api/v1/recipes/{id}', [$recipeController, 'update']);
        $app->delete('/api/v1/recipes/{id}', [$recipeController, 'delete']);
        $app->get('/api/v1/recipes/{id}/export/{format}', [$recipeController, 'export']);
        $app->post('/api/v1/recipes/{id}/bring-export', [$recipeController, 'bringExport']);
        $app->post('/api/v1/recipes/{id}/images', [$recipeController, 'uploadImage']);
        $app->get('/api/v1/recipes/{id}/images/{imageId}', [$recipeController, 'serveImage']);
        $app->delete('/api/v1/recipes/{id}/images/{imageId}', [$recipeController, 'deleteImage']);
        $app->put('/api/v1/recipes/{id}/primary-image', [$recipeController, 'setPrimaryImage']);
        $app->put('/api/v1/recipes/{id}/rating', [$recipeController, 'rate']);
        $app->delete('/api/v1/recipes/{id}/rating', [$recipeController, 'deleteRating']);
        $app->put('/api/v1/recipes/{id}/tags', [$recipeController, 'updateTags']);

        $app->get('/api/v1/watchlist', [$watchlistController, 'index']);
        $app->post('/api/v1/watchlist', [$watchlistController, 'add']);
        $app->put('/api/v1/watchlist/order', [$watchlistController, 'reorder']);
        $app->delete('/api/v1/watchlist/{recipeId}', [$watchlistController, 'remove']);
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
        $app->get('/', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator, $recipePageSizes, $recipeDefaultPageSize, $defaultFontScaleDesktop, $defaultFontScaleMobile) {
            ob_start();
            // VERSION is regenerated by .githooks/pre-commit on every commit
            // (see CLAUDE.md); 'dev' only if the file is missing.
            $appVersion = 'dev';
            $versionFile = $rootDir . '/VERSION';
            if (is_file($versionFile)) {
                $appVersion = trim(file_get_contents($versionFile));
            }
            require $rootDir . '/templates/app.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        // PWA Web Share Target fallback (see site.webmanifest/sw.js): normally
        // the service worker answers this POST itself; only when no worker is
        // active (first visit, worker disabled) does it reach the server,
        // which can't read the shared content but still lands the user on
        // the import view.
        $shareTargetFallback = function (Request $req, Response $res) use ($baseUrl) {
            // GET share (the manifest's method): the text arrives as query
            // parameters and is handed to the import view via its own
            // hash query. Without a worker a POST body can't be read.
            $params = $req->getQueryParams();
            $text = trim(implode("\n", array_filter([(string) ($params['title'] ?? ''), (string) ($params['text'] ?? ''), (string) ($params['url'] ?? '')])));
            $hash = '#/recipes/import-photo' . ($text !== '' ? '?sharedText=' . rawurlencode(mb_substr($text, 0, 4000)) : '');

            return $res->withStatus(303)->withHeader('Location', $baseUrl . '/' . $hash);
        };
        $app->get('/share-target', $shareTargetFallback);
        $app->post('/share-target', $shareTargetFallback);

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
        // Signed-out landing page for both the invite flow and the forgot-
        // password flow (?token=...) - see AuthService::setNewPassword().
        $app->get('/set-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            require $rootDir . '/templates/set-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        // Landing page for the "confirm your new email" link mailed by
        // AuthService::updateProfile() - public/unauthenticated, same
        // reasoning as /set-password above (the token itself is the proof).
        $app->get('/confirm-email', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            require $rootDir . '/templates/confirm-email.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        // Entry point for AuthController::forgotPassword() - standalone
        // page (not part of the SPA), linked from the login form
        // (public/js/views/login.js), mirroring YTAN's own
        // /forgot-password page (todo.md "Benutzerverwaltung und
        // Passwort-Vergessen-Funktion").
        $app->get('/forgot-password', function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator) {
            ob_start();
            require $rootDir . '/templates/forgot-password.php';
            $res->getBody()->write(ob_get_clean());

            return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        });
        // Bring!'s server fetches this URL unauthenticated to parse the
        // recipe's ingredients out of embedded schema.org markup (todo.md
        // "Anbindung der Einkaufs-App Bring!") - see BringService /
        // RecipeController::bringExport(). Token possession is the only
        // check; normal visibility was already enforced once, when the
        // token was minted (RecipeRepository::createBringExportToken()).
        $app->get('/bring-export/{token}', function (Request $req, Response $res, array $args) use ($rootDir, $recipeController) {
            $html = $recipeController->renderBringExportPage($rootDir, $args['token']);
            if ($html === null) {
                return $res->withStatus(404);
            }
            $res->getBody()->write($html);

            return $res
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Robots-Tag', 'noindex');
        });

        // AdminLTE-based admin area (todo.md "Admin-Oberfläche") - no
        // server-side auth check here, same as the main SPA shell; the real
        // gate is server-side on the API via BaseController::requireAdmin()
        // (see templates/partials/admin-shell-header.php's doc-comment).
        // The translate tool used to be additionally gated behind a
        // "translate_tool_enabled" setting (mirroring YTAN's
        // TRANSLATE_TOOL_ENABLED), but todo.md's "Einstellungen
        // überarbeiten" called that toggle unnecessary - requireAdmin() is
        // the only gate now, same as every other admin page.
        $adminPageRoute = function (string $template) use ($rootDir, $appName, $baseUrl, $translator) {
            return function (Request $req, Response $res) use ($rootDir, $appName, $baseUrl, $translator, $template) {
                ob_start();
                $t = fn (string $key, array $vars = []) => $translator->t($key, $vars);
                require $rootDir . '/templates/' . $template;
                $res->getBody()->write(ob_get_clean());

                return $res->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
            };
        };
        $app->get('/admin', $adminPageRoute('admin.php'));
        $app->get('/admin/users', $adminPageRoute('admin-users.php'));
        $app->get('/admin/tags', $adminPageRoute('admin-tags.php'));
        $app->get('/admin/placeholder-images', $adminPageRoute('admin-placeholder-images.php'));
        $app->get('/admin/deleted-recipes', $adminPageRoute('admin-deleted-recipes.php'));
        $app->get('/admin/settings', $adminPageRoute('admin-settings.php'));
        $app->get('/admin/translate', $adminPageRoute('translate.php'));
        $app->get('/admin/chefkoch-import', $adminPageRoute('admin-chefkoch-import.php'));
        $app->get('/admin/gemini', $adminPageRoute('admin-gemini.php'));
        $app->get('/admin/photo-import', $adminPageRoute('admin-photo-import.php'));

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
