<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Dompdf\Dompdf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\BringService;
use Kochbuch\Service\RecipeImageService;
use Kochbuch\Service\RecipeOcrParser;
use Kochbuch\Service\SchemaOrgRecipeParser;
use Kochbuch\Service\VisionOcrService;

final class RecipeController extends BaseController
{
    private const DIFFICULTIES = ['easy', 'normal', 'hard', 'challenging'];
    private const VISIBILITIES = ['private', 'internal', 'public'];

    // 10 minutes - generous enough to cover the time between minting the
    // link and Bring!'s server(s) actually fetching it (once when the
    // deeplink is generated, possibly again later when the app processes
    // it), short enough to bound the temporary public exposure window.
    // There's no "import finished" callback from Bring! to expire it early.
    private const BRING_TOKEN_TTL_SECONDS = 600;

    // Same limits as RecipeImageService (uploadImage()) - kept as separate
    // constants rather than importing that service's, since ocr()'s images
    // are transient OCR input, never persisted under a recipe id.
    private const OCR_MAX_BYTES = 5 * 1024 * 1024;
    private const OCR_MAX_IMAGES = 8;
    private const OCR_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    // JSON-LD recipe files are small structured text, not photos - a much
    // smaller per-file cap than OCR's image uploads, and a more generous
    // file count since this is a bulk-import feature by design.
    private const IMPORT_JSON_MAX_BYTES = 2 * 1024 * 1024;
    private const IMPORT_JSON_MAX_FILES = 20;

    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly RecipeImageService $images,
        private readonly int $defaultPerPage = 10,
        private readonly BringService $bring = new BringService(),
        private readonly string $appUrl = '',
        // todo.md "Last recipies configurable" - admin-configurable via
        // /admin/settings (setting key "home_latest_recipes_count"), see
        // App.php's wiring; 6 is the fallback for a fresh install before
        // that setting row exists (same convention as $defaultPerPage above).
        private readonly int $latestRecipesLimit = 6,
        // todo.md "Importing Photos of Handwritten Recipes" - see ocr().
        private readonly VisionOcrService $visionOcr = new VisionOcrService(),
        private readonly RecipeOcrParser $ocrParser = new RecipeOcrParser(),
        // todo.md "Importing schema.org Recipe JSON-LD" - see importJson().
        private readonly SchemaOrgRecipeParser $schemaOrgParser = new SchemaOrgRecipeParser(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();

        // category_id="uncategorized" is the virtual "eigene Rezepte ohne
        // Kategorie" pseudo-category (todo.md "Benutzeroberfläche und
        // Suche") - a sentinel string rather than a real category row, so
        // it's peeled off into its own boolean filter here rather than
        // reaching RecipeRepository as a fake numeric id.
        $categoryParam = $params['category_id'] ?? null;

        $filters = [
            'q' => trim((string) ($params['q'] ?? '')),
            'difficulty' => $params['difficulty'] ?? null,
            'vegan' => !empty($params['vegan']),
            'vegetarian' => !empty($params['vegetarian']),
            'pescetarian' => !empty($params['pescetarian']),
            'mine_only' => !empty($params['mine']),
            'uncategorized_mine' => $categoryParam === 'uncategorized',
            'category_id' => ($categoryParam !== null && $categoryParam !== '' && $categoryParam !== 'uncategorized') ? (int) $categoryParam : null,
            'page' => isset($params['page']) ? (int) $params['page'] : 1,
            'per_page' => isset($params['per_page']) ? (int) $params['per_page'] : $this->defaultPerPage,
        ];

        $result = $this->recipes->search($filters, $auth['sub'] ?? null);

        return $this->json($response, ['data' => $result]);
    }

    /**
     * Admin-only "all recipes" listing (todo.md "Schnelle Tag-Zuordnung im
     * Admin-Bereich") - same homepage filter set as index(), minus
     * mine/uncategorized (meaningless once visibility is bypassed), and
     * search()'s visibility check skipped entirely so an admin sees every
     * recipe regardless of owner/visibility.
     */
    public function adminIndex(Request $request, Response $response): Response
    {
        $auth = $this->requireAdmin($request);
        $params = $request->getQueryParams();

        $categoryParam = $params['category_id'] ?? null;

        $filters = [
            'q' => trim((string) ($params['q'] ?? '')),
            'difficulty' => $params['difficulty'] ?? null,
            'vegan' => !empty($params['vegan']),
            'vegetarian' => !empty($params['vegetarian']),
            'pescetarian' => !empty($params['pescetarian']),
            'untagged' => !empty($params['untagged']),
            'category_id' => ($categoryParam !== null && $categoryParam !== '') ? (int) $categoryParam : null,
            'page' => isset($params['page']) ? (int) $params['page'] : 1,
            'per_page' => isset($params['per_page']) ? (int) $params['per_page'] : $this->defaultPerPage,
        ];

        $result = $this->recipes->search($filters, (int) $auth['sub'], bypassVisibility: true);

        return $this->json($response, ['data' => $result]);
    }

    /**
     * Tags-only update (todo.md "Schnelle Tag-Zuordnung im Admin-Bereich") -
     * deliberately separate from update(), which requires the full recipe
     * body; this only ever touches the recipe's tags.
     */
    public function updateTags(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }

        $body = $this->jsonBody($request);
        $tags = is_array($body['tags'] ?? null) ? array_map('strval', $body['tags']) : [];
        $this->recipes->updateTags($recipe['id'], $tags);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])]);
    }

    /**
     * Home page feed (todo.md "Anzeige der Rezepte auf Startseite") - see
     * RecipeRepository::homeFeed() for the "Latest Recipes"/"Random
     * Recipes" split this backs. `seed` drives the random section's
     * stable-but-shuffled ordering; when the caller doesn't have one yet
     * (first arrival at the home view), one is minted here and always
     * echoed back in the response so the frontend can persist it in the
     * URL for subsequent pagination requests to reuse.
     */
    public function home(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();

        $randomPage = isset($params['random_page']) ? (int) $params['random_page'] : 1;
        $randomPerPage = isset($params['random_per_page']) ? (int) $params['random_per_page'] : $this->defaultPerPage;
        $seed = isset($params['seed']) && ctype_digit((string) $params['seed']) ? (int) $params['seed'] : random_int(1, 1_000_000);

        $result = $this->recipes->homeFeed($auth['sub'] ?? null, $this->latestRecipesLimit, $randomPage, $randomPerPage, $seed);

        return $this->json($response, ['data' => array_merge($result, ['seed' => $seed])]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $recipe = $this->findVisible($request, (int) $args['id']);

        return $this->json($response, ['data' => $recipe]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $data = $this->validate($this->jsonBody($request));

        $id = $this->recipes->create((int) $auth['sub'], $data);

        return $this->json($response, ['data' => $this->recipes->find($id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        $this->assertOwnerOrAdmin($auth, $recipe['user_id']);

        $data = $this->validate($this->jsonBody($request));
        $this->recipes->update($recipe['id'], $data);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        $this->assertOwnerOrAdmin($auth, $recipe['user_id']);

        foreach ($recipe['images'] as $imageId) {
            $image = $this->recipes->findImage($recipe['id'], $imageId);
            if ($image !== null) {
                $this->images->delete($recipe['id'], $image['filename']);
            }
        }
        $this->recipes->delete($recipe['id']);

        return $response->withStatus(204);
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        $this->assertOwnerOrAdmin($auth, $recipe['user_id']);

        $uploaded = $request->getUploadedFiles();
        if (!isset($uploaded['image'])) {
            throw new ValidationException('No image file provided.', 'recipe.image_missing');
        }

        $filename = $this->images->store($recipe['id'], $uploaded['image'], count($recipe['images']));
        $this->recipes->addImage($recipe['id'], $filename);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])], 201);
    }

    /**
     * todo.md "Importing Photos of Handwritten Recipes": OCRs one or more
     * uploaded photos via VisionOcrService and heuristically parses the
     * combined recognized text into a draft recipe (RecipeOcrParser) for
     * the frontend to pre-fill the normal create form with - this never
     * writes a recipe itself, so it doesn't touch RecipeRepository at all.
     * Images are validated in-memory only (same limits as uploadImage()/
     * RecipeImageService, reused here for consistent error codes) and never
     * written to disk - they're transient OCR input, not a persisted
     * gallery image (there's no recipe id yet to store them under).
     */
    public function ocr(Request $request, Response $response): Response
    {
        $this->requireAuthUser($request);

        $uploaded = $request->getUploadedFiles()['images'] ?? null;
        if (!is_array($uploaded) || $uploaded === []) {
            throw new ValidationException('No image file provided.', 'recipe.image_missing');
        }
        if (count($uploaded) > self::OCR_MAX_IMAGES) {
            throw new ValidationException('A recipe can have at most 8 images.', 'recipe.too_many_images');
        }

        // Parsed per image, not concatenated first - each photo is its own
        // page with its own column geometry (RecipeOcrParser needs a single
        // page's own paragraph bounding boxes to tell the two columns
        // apart), so the per-page drafts are merged afterward instead.
        $name = null;
        $ingredients = [];
        $steps = [];
        $notesParts = [];
        $rawTexts = [];
        foreach ($uploaded as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException('Image upload failed.', 'recipe.image_upload_failed');
            }
            if ($file->getSize() > self::OCR_MAX_BYTES) {
                throw new ValidationException('Image is larger than 5 MB.', 'recipe.image_too_large');
            }

            $bytes = (string) $file->getStream()->getContents();
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, self::OCR_ALLOWED_MIMES, true)) {
                throw new ValidationException('Only JPEG, PNG or WebP images are allowed.', 'recipe.image_invalid_type');
            }

            $document = $this->visionOcr->recognizeDocument($bytes);
            if (trim($document['text']) === '') {
                continue;
            }
            $rawTexts[] = $document['text'];

            $pageDraft = $this->ocrParser->parse($document);
            $name ??= $pageDraft['name'];
            $ingredients = array_merge($ingredients, $pageDraft['ingredients']);
            $steps = array_merge($steps, $pageDraft['steps']);
            if ($pageDraft['notes'] !== null) {
                $notesParts[] = $pageDraft['notes'];
            }
        }

        $draft = [
            'name' => $name,
            'ingredients' => $ingredients,
            'steps' => $steps,
            'notes' => $notesParts === [] ? null : implode("\n\n", $notesParts),
            'raw_text' => implode("\n\n---\n\n", $rawTexts),
        ];

        return $this->json($response, ['data' => $draft]);
    }

    /**
     * todo.md "Importing schema.org Recipe JSON-LD": bulk-imports one
     * recipe per uploaded JSON file (SchemaOrgRecipeParser maps each into
     * RecipeController::validate()'s own payload shape - the file's data is
     * structured, not handwriting, so unlike ocr() this creates recipes
     * directly rather than handing a draft to the frontend for review). A
     * bad file never aborts the whole batch - each file gets its own
     * {filename, status, ...} result entry, so one malformed upload among
     * twenty doesn't lose the other nineteen.
     */
    public function importJson(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        $uploaded = $request->getUploadedFiles()['files'] ?? null;
        if (!is_array($uploaded) || $uploaded === []) {
            throw new ValidationException('No JSON file provided.', 'recipe.import_json_missing');
        }
        if (count($uploaded) > self::IMPORT_JSON_MAX_FILES) {
            throw new ValidationException('At most 20 files can be imported at once.', 'recipe.import_json_too_many_files');
        }

        $results = [];
        foreach ($uploaded as $file) {
            $filename = $file->getClientFilename() ?? 'recipe.json';

            if ($file->getError() !== UPLOAD_ERR_OK || $file->getSize() > self::IMPORT_JSON_MAX_BYTES) {
                $results[] = ['filename' => $filename, 'status' => 'error', 'error_code' => 'file_too_large_or_invalid'];
                continue;
            }

            $decoded = json_decode((string) $file->getStream()->getContents(), true);
            if (!is_array($decoded)) {
                $results[] = ['filename' => $filename, 'status' => 'error', 'error_code' => 'invalid_json'];
                continue;
            }

            try {
                $mapped = $this->schemaOrgParser->parse($decoded);
                $imageUrl = $mapped['image_url'];
                unset($mapped['image_url']);
                $data = $this->validate($mapped);
            } catch (\InvalidArgumentException) {
                $results[] = ['filename' => $filename, 'status' => 'error', 'error_code' => 'missing_name'];
                continue;
            } catch (ValidationException $e) {
                $results[] = ['filename' => $filename, 'status' => 'error', 'error_code' => $e->getErrorCode()];
                continue;
            }

            $id = $this->recipes->create((int) $auth['sub'], $data);

            if ($imageUrl !== null) {
                $bytes = @file_get_contents($imageUrl, false, stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]));
                if ($bytes !== false) {
                    $imageFilename = $this->images->storeBytes($id, $bytes, 0);
                    if ($imageFilename !== null) {
                        $this->recipes->addImage($id, $imageFilename);
                    }
                }
            }

            $results[] = ['filename' => $filename, 'status' => 'created', 'id' => $id, 'name' => $data['name']];
        }

        return $this->json($response, ['data' => ['results' => $results]]);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        $this->assertOwnerOrAdmin($auth, $recipe['user_id']);

        $image = $this->recipes->findImage($recipe['id'], (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->recipes->removeImage($recipe['id'], $image['id']);
        $this->images->delete($recipe['id'], $image['filename']);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])]);
    }

    public function setPrimaryImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipe = $this->recipes->find((int) $args['id']);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        $this->assertOwnerOrAdmin($auth, $recipe['user_id']);

        $body = $this->jsonBody($request);
        $imageId = isset($body['image_id']) ? (int) $body['image_id'] : null;
        if ($imageId !== null && $this->recipes->findImage($recipe['id'], $imageId) === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->recipes->setPrimaryImage($recipe['id'], $imageId);

        return $this->json($response, ['data' => $this->recipes->find($recipe['id'])]);
    }

    public function serveImage(Request $request, Response $response, array $args): Response
    {
        $recipe = $this->findVisible($request, (int) $args['id']);
        $image = $this->recipes->findImage($recipe['id'], (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $path = $this->images->path($recipe['id'], $image['filename']);
        if (!is_file($path)) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write((string) file_get_contents($path));

        return $response
            ->withHeader('Content-Type', $this->images->mimeTypeFor($image['filename']))
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    /**
     * Portion-scaled ingredient amounts are computed client-side (see
     * public/js/views/recipe-detail.js) - export always uses the recipe's
     * own base `servings` amount, same as the JSON/detail API response.
     */
    public function export(Request $request, Response $response, array $args): Response
    {
        $recipe = $this->findVisible($request, (int) $args['id']);
        $format = strtolower((string) ($args['format'] ?? 'json'));

        return match ($format) {
            'json' => $this->exportJson($recipe, $response),
            'xml' => $this->exportXml($recipe, $response),
            'pdf' => $this->exportPdf($recipe, $response),
            default => throw new ValidationException('Unsupported export format.', 'recipe.invalid_export_format'),
        };
    }

    /**
     * Requests a Bring! shopping-list import deeplink for this recipe
     * (todo.md "Anbindung der Einkaufs-App Bring!"). findVisible() is the
     * same visibility gate export()/show() already use, checked once here
     * at request time - Bring!'s own later fetch of the minted token URL
     * only checks token possession, not Kochbuch's normal visibility rules
     * (see RecipeRepository::createBringExportToken()'s doc-comment), so
     * this works for private/internal recipes too, not just public ones.
     */
    public function bringExport(Request $request, Response $response, array $args): Response
    {
        $recipe = $this->findVisible($request, (int) $args['id']);
        $body = $this->jsonBody($request);
        $requestedServings = (float) ($body['requested_servings'] ?? 0);
        if ($requestedServings <= 0) {
            $requestedServings = (float) $recipe['servings'];
        }

        $token = $this->recipes->createBringExportToken($recipe['id'], self::BRING_TOKEN_TTL_SECONDS);
        $url = rtrim($this->appUrl, '/') . '/bring-export/' . $token;
        $deeplink = $this->bring->requestDeeplink($url, (int) $recipe['servings'], $requestedServings);

        return $this->json($response, ['data' => ['deeplink' => $deeplink]]);
    }

    /**
     * Renders the public Bring! export page for a token minted by
     * bringExport() (todo.md "Anbindung der Einkaufs-App Bring!"), or null
     * if the token is missing/expired - the App.php route this backs turns
     * null into a plain 404 without ever touching this method's output.
     * Takes $rootDir as a parameter rather than a constructor dependency,
     * since nothing else on this controller needs the filesystem root.
     */
    public function renderBringExportPage(string $rootDir, string $token): ?string
    {
        $recipeId = $this->recipes->findRecipeIdForValidBringExportToken($token);
        $recipe = $recipeId !== null ? $this->recipes->find($recipeId) : null;
        if ($recipe === null) {
            return null;
        }

        ob_start();
        require $rootDir . '/templates/recipe-bring-export.php';

        return ob_get_clean();
    }

    private function exportJson(array $recipe, Response $response): Response
    {
        $response->getBody()->write((string) json_encode($recipe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->slug($recipe['name']) . '.json"');
    }

    private function exportXml(array $recipe, Response $response): Response
    {
        $xml = new \SimpleXMLElement('<recipe/>');
        $this->arrayToXml($recipe, $xml);

        $response->getBody()->write((string) $xml->asXML());

        return $response
            ->withHeader('Content-Type', 'application/xml')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->slug($recipe['name']) . '.xml"');
    }

    private function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_int($key)) {
                $key = 'item';
            }
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->arrayToXml($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string) $value, ENT_XML1));
            }
        }
    }

    private function exportPdf(array $recipe, Response $response): Response
    {
        $html = '<h1>' . htmlspecialchars($recipe['name']) . '</h1>';
        if ($recipe['description']) {
            $html .= '<p>' . nl2br(htmlspecialchars($recipe['description'])) . '</p>';
        }
        $html .= '<p><b>Portionen:</b> ' . (int) $recipe['servings'] . '</p>';
        $html .= '<h2>Zutaten</h2><ul>';
        foreach ($recipe['ingredients'] as $ingredient) {
            $html .= !empty($ingredient['is_heading'])
                ? '<li style="list-style:none;font-weight:bold;margin-left:-1.5em;">' . htmlspecialchars((string) $ingredient['name']) . '</li>'
                : '<li>' . htmlspecialchars(self::pdfIngredientLine($ingredient)) . '</li>';
        }
        $html .= '</ul><h2>Zubereitung</h2>';
        $html .= self::pdfStepsHtml($recipe['steps']);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $response->getBody()->write($dompdf->output());

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->slug($recipe['name']) . '.pdf"');
    }

    /**
     * One ingredient line for the PDF export, left to right: amount, unit,
     * name, then the note in parentheses - empty parts are skipped rather
     * than leaving double spaces or an empty "()" behind.
     */
    public static function pdfIngredientLine(array $ingredient): string
    {
        $parts = [];
        if (($ingredient['amount'] ?? null) !== null) {
            $parts[] = rtrim(rtrim(number_format((float) $ingredient['amount'], 2, ',', ''), '0'), ',');
        }
        foreach (['unit', 'name'] as $key) {
            $value = trim((string) ($ingredient[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $note = trim((string) ($ingredient['note'] ?? ''));
        if ($note !== '') {
            $parts[] = '(' . $note . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * The steps list for the PDF export as one or more <ol> segments split
     * around heading rows, numbered so a heading never consumes a step
     * number and the steps after it continue the same count (matching the
     * detail view's CSS-counter treatment in style.css's .step-list rules,
     * which skips counter-increment on a heading row the same way).
     */
    public static function pdfStepsHtml(array $steps): string
    {
        $html = '';
        $number = 1;
        $listOpen = false;
        foreach ($steps as $step) {
            $instruction = (string) ($step['instruction'] ?? '');
            if (!empty($step['is_heading'])) {
                if ($listOpen) {
                    $html .= '</ol>';
                    $listOpen = false;
                }
                $html .= '<p style="font-weight:bold;margin:0.75em 0 0.25em;">' . htmlspecialchars($instruction) . '</p>';
                continue;
            }
            if (!$listOpen) {
                $html .= '<ol start="' . $number . '">';
                $listOpen = true;
            }
            $html .= '<li>' . nl2br(htmlspecialchars($instruction)) . '</li>';
            $number++;
        }
        if ($listOpen) {
            $html .= '</ol>';
        }

        return $html;
    }

    private function slug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? ''));

        return trim($slug, '-') ?: 'recipe';
    }

    /**
     * Three visibility levels (todo.md "Sichtbarkeitsstatus"): "public" -
     * anyone, including a logged-out visitor; "internal" - any logged-in
     * user, not just the owner; "private" - owner or admin only.
     */
    private function findVisible(Request $request, int $id): array
    {
        $recipe = $this->recipes->find($id);
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }

        $auth = $request->getAttribute('auth');
        $isOwnerOrAdmin = $auth !== null && ((int) $auth['sub'] === $recipe['user_id'] || ($auth['is_admin'] ?? false));

        $visible = match ($recipe['visibility']) {
            'public' => true,
            'internal' => $auth !== null,
            default => $isOwnerOrAdmin,
        };

        if (!$visible && !$isOwnerOrAdmin) {
            throw new ForbiddenException('This recipe is private.');
        }

        return $recipe;
    }

    private function validate(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('A recipe name is required.', 'recipe.name_required');
        }

        $difficulty = $body['difficulty'] ?? 'normal';
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new ValidationException('Invalid difficulty.', 'recipe.invalid_difficulty');
        }

        $visibility = $body['visibility'] ?? 'internal';
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new ValidationException('Invalid visibility.', 'recipe.invalid_visibility');
        }

        $servings = (int) ($body['servings'] ?? 4);
        if ($servings < 1) {
            throw new ValidationException('Servings must be at least 1.', 'recipe.invalid_servings');
        }

        return [
            'name' => $name,
            'description' => $this->nullableString($body['description'] ?? null),
            'servings' => $servings,
            'difficulty' => $difficulty,
            'prep_time_minutes' => $this->nullableInt($body['prep_time_minutes'] ?? null),
            'rest_time_minutes' => $this->nullableInt($body['rest_time_minutes'] ?? null),
            'cook_time_minutes' => $this->nullableInt($body['cook_time_minutes'] ?? null),
            'notes' => $this->nullableString($body['notes'] ?? null),
            'calories' => $this->nullableInt($body['calories'] ?? null),
            'allergen_info' => $this->nullableString($body['allergen_info'] ?? null),
            'is_vegan' => !empty($body['is_vegan']),
            'is_vegetarian' => !empty($body['is_vegetarian']),
            'is_pescetarian' => !empty($body['is_pescetarian']),
            'source' => $this->nullableString($body['source'] ?? null),
            'source_url' => $this->nullableString($body['source_url'] ?? null),
            'visibility' => $visibility,
            'ingredients' => is_array($body['ingredients'] ?? null) ? $body['ingredients'] : [],
            'steps' => is_array($body['steps'] ?? null) ? $body['steps'] : [],
            'tags' => is_array($body['tags'] ?? null) ? $body['tags'] : [],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
