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
use Kochbuch\Service\RecipeImageService;

final class RecipeController extends BaseController
{
    private const DIFFICULTIES = ['easy', 'normal', 'hard', 'challenging'];
    private const VISIBILITIES = ['private', 'internal', 'public'];

    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly RecipeImageService $images,
        private readonly int $defaultPerPage = 10,
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
