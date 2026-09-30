<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ApiException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\ChefkochImportService;
use Kochbuch\Service\IngredientLineParser;
use Kochbuch\Service\RecipeDataValidator;
use Kochbuch\Service\RecipeImageService;
use Kochbuch\Service\SchemaOrgRecipeParser;

/**
 * todo.md "Import aus Kochbuch von Chefkoch.de" - admin-only tool that lists
 * a Chefkoch.de user's personal recipe collection and lets the admin
 * selectively import individual ones, flagging recipes already imported
 * (by source_url, see RecipeRepository::existingSourceUrls()) so they're
 * opt-in rather than re-imported automatically. See ChefkochImportService's
 * own doc-comment for the endpoints/data shapes this is built on.
 */
final class ChefkochImportController extends BaseController
{
    // Applied to every recipe this importer creates, regardless of
    // collection, so imports stay identifiable afterward.
    private const IMPORT_TAG = 'chefkoch-import';

    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly RecipeImageService $images,
        private readonly ChefkochImportService $chefkoch = new ChefkochImportService(),
        private readonly SchemaOrgRecipeParser $schemaOrgParser = new SchemaOrgRecipeParser(),
        private readonly IngredientLineParser $ingredientParser = new IngredientLineParser(),
        private readonly RecipeDataValidator $validator = new RecipeDataValidator(),
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $token = $this->resolveToken($body);
        $ownerId = $this->resolveOwnerId($token);

        $found = $this->chefkoch->listCookbook($ownerId, $token);
        $existing = array_flip($this->recipes->existingSourceUrls(array_column($found, 'source_url')));

        $recipes = array_map(static function (array $recipe) use ($existing) {
            $recipe['already_imported'] = isset($existing[$recipe['source_url']]);

            return $recipe;
        }, $found);

        return $this->json($response, ['data' => ['recipes' => $recipes]]);
    }

    /**
     * Fetches, maps, validates and creates each selected recipe - one
     * try/catch per item so a single failure (a shape mismatch, a
     * temporarily unreachable page, ...) never aborts the rest of the
     * batch, same philosophy as RecipeController::importJson().
     */
    public function import(Request $request, Response $response): Response
    {
        $auth = $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $token = $this->resolveToken($body);
        $ownerId = $this->resolveOwnerId($token);

        $items = is_array($body['recipes'] ?? null) ? $body['recipes'] : [];
        if ($items === []) {
            throw new ValidationException('No recipes selected.', 'chefkoch.no_recipes_selected');
        }

        $results = [];
        foreach ($items as $item) {
            $chefkochId = trim((string) ($item['chefkoch_id'] ?? ''));
            $sourceUrl = trim((string) ($item['source_url'] ?? ''));
            $collection = trim((string) ($item['collection'] ?? ''));
            $name = (string) ($item['name'] ?? $chefkochId);

            if ($chefkochId === '' || $sourceUrl === '') {
                $results[] = ['name' => $name, 'status' => 'error', 'error_code' => 'chefkoch.invalid_item'];
                continue;
            }

            try {
                $mapped = $this->chefkoch->fetchRecipe($chefkochId, $sourceUrl, $ownerId, $token, $this->schemaOrgParser, $this->ingredientParser);
                $imageUrl = $mapped['image_url'] ?? null;
                unset($mapped['image_url']);
                // todo.md "Import aus Kochbuch von Chefkoch.de" carries over
                // the original import's collection-to-tag mapping (done.md
                // "Kategorien entsprechend der Chefkoch-Sammlungen").
                if ($collection !== '' && !in_array($collection, $mapped['tags'] ?? [], true)) {
                    $mapped['tags'][] = $collection;
                }
                // Every recipe created through this importer is marked so it
                // stays identifiable afterward, independent of source_url.
                if (!in_array(self::IMPORT_TAG, $mapped['tags'] ?? [], true)) {
                    $mapped['tags'][] = self::IMPORT_TAG;
                }
                $data = $this->validator->validate($mapped);
            } catch (ValidationException $e) {
                $results[] = ['name' => $name, 'status' => 'error', 'error_code' => $e->getErrorCode()];
                continue;
            } catch (ApiException $e) {
                $results[] = ['name' => $name, 'status' => 'error', 'error_code' => $e->getErrorCode()];
                continue;
            } catch (Throwable $e) {
                error_log('Kochbuch: Chefkoch import of ' . $sourceUrl . ' failed: ' . $e->getMessage());
                $results[] = ['name' => $name, 'status' => 'error', 'error_code' => 'chefkoch.recipe_fetch_failed'];
                continue;
            }

            $id = $this->recipes->create((int) $auth['sub'], $data);

            if (!empty($imageUrl)) {
                $bytes = $this->chefkoch->fetchImageBytes($imageUrl, $token);
                if ($bytes !== null) {
                    $imageFilename = $this->images->storeBytes($id, $bytes, 0);
                    if ($imageFilename !== null) {
                        $this->recipes->addImage($id, $imageFilename);
                    }
                }
            }

            $results[] = ['name' => $data['name'], 'status' => 'created', 'id' => $id];
        }

        return $this->json($response, ['data' => ['results' => $results]]);
    }

    /**
     * A pasted API token, never persisted anywhere beyond this one request
     * (todo.md: "nicht dauerhaft in der Datenbank speichern") - there is no
     * username/password login (see ChefkochImportService's doc-comment for
     * why).
     */
    private function resolveToken(array $body): string
    {
        $token = trim((string) ($body['token'] ?? ''));
        if ($token === '') {
            throw new ValidationException('A Chefkoch API token is required.', 'chefkoch.token_required');
        }

        return $token;
    }

    /**
     * The token itself encodes the owner id as its leading segment before
     * the first "-" (live-verified 2026-10-01, with the user's help reading
     * their own token's structure) - no separate form field needed, unlike
     * the first version of this which asked for it explicitly.
     */
    private function resolveOwnerId(string $token): string
    {
        $dashPos = strpos($token, '-');
        if ($dashPos === false || $dashPos === 0) {
            throw new ValidationException('Could not determine your Chefkoch user id from the token.', 'chefkoch.owner_id_required');
        }

        return substr($token, 0, $dashPos);
    }
}
