<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\Watchlist\WatchlistRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;

/**
 * The logged-in user's "Merkliste" (todo.md "Watchlist"):
 *
 *   GET    /api/v1/watchlist                    recipes in list order
 *   POST   /api/v1/watchlist        {recipe_id} append to the end (idempotent)
 *   DELETE /api/v1/watchlist/{recipeId}         remove
 *   PUT    /api/v1/watchlist/order  {recipe_ids} new order
 *
 * Only recipes the user may see can be added. A recipe that later becomes
 * invisible to the user (made private by its owner) or is deleted simply
 * drops out of the returned list - the entry itself stays (or cascades
 * away on a hard delete).
 */
final class WatchlistController extends BaseController
{
    public function __construct(
        private readonly WatchlistRepository $watchlist,
        private readonly RecipeRepository $recipes,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $ids = $this->watchlist->recipeIds((int) $auth['sub']);
        $found = $this->recipes->findMany($ids);

        $list = [];
        foreach ($ids as $id) {
            if (isset($found[$id]) && self::canSee($auth, $found[$id])) {
                $list[] = $found[$id];
            }
        }

        return $this->json($response, ['data' => $list]);
    }

    public function add(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $recipeId = (int) ($this->jsonBody($request)['recipe_id'] ?? 0);
        $recipe = $recipeId > 0 ? $this->recipes->find($recipeId) : null;
        if ($recipe === null) {
            throw new NotFoundException('Recipe not found.');
        }
        if (!self::canSee($auth, $recipe)) {
            throw new ForbiddenException('This recipe is private.');
        }

        $this->watchlist->add((int) $auth['sub'], $recipeId);

        return $this->json($response, ['data' => ['recipe_ids' => $this->watchlist->recipeIds((int) $auth['sub'])]], 201);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->watchlist->remove((int) $auth['sub'], (int) $args['recipeId']);

        return $this->json($response, ['data' => ['recipe_ids' => $this->watchlist->recipeIds((int) $auth['sub'])]]);
    }

    public function reorder(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $ids = $this->jsonBody($request)['recipe_ids'] ?? [];
        $this->watchlist->reorder((int) $auth['sub'], is_array($ids) ? $ids : []);

        return $this->json($response, ['data' => ['recipe_ids' => $this->watchlist->recipeIds((int) $auth['sub'])]]);
    }

    /**
     * Same visibility rule as RecipeController::findVisible(): public and
     * internal recipes for every logged-in user, private ones only for the
     * owner or an admin.
     */
    private static function canSee(array $auth, array $recipe): bool
    {
        if (in_array($recipe['visibility'], ['public', 'internal'], true)) {
            return true;
        }

        return (int) $auth['sub'] === (int) $recipe['user_id'] || (bool) ($auth['is_admin'] ?? false);
    }
}
