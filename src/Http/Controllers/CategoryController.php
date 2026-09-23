<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Category\CategoryRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\ValidationException;

final class CategoryController extends BaseController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly RecipeRepository $recipes,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $userId = (int) $auth['sub'];
        $categories = $this->categories->listForUser($userId);

        // ?recipe_id=... additionally marks which of the user's categories
        // already contain that recipe - the "add this recipe to a
        // category" checklist on the recipe detail page needs exactly this
        // shape (see public/js/views/recipe-detail.js).
        $recipeId = $request->getQueryParams()['recipe_id'] ?? null;
        if ($recipeId !== null) {
            $containing = $this->categories->categoryIdsContaining($userId, (int) $recipeId);
            $categories = array_map(static function (array $category) use ($containing) {
                $category['contains_recipe'] = in_array($category['id'], $containing, true);

                return $category;
            }, $categories);
        }

        return $this->json($response, ['data' => $categories]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $name = trim((string) ($this->jsonBody($request)['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('A category name is required.', 'category.name_required');
        }

        $id = $this->categories->create((int) $auth['sub'], $name);

        return $this->json($response, ['data' => $this->categories->find($id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $category = $this->requireOwnCategory($auth, (int) $args['id']);

        $name = trim((string) ($this->jsonBody($request)['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('A category name is required.', 'category.name_required');
        }

        $this->categories->rename($category['id'], $name);

        return $this->json($response, ['data' => $this->categories->find($category['id'])]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $category = $this->requireOwnCategory($auth, (int) $args['id']);

        $this->categories->delete($category['id']);

        return $response->withStatus(204);
    }

    public function recipes(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $category = $this->requireOwnCategory($auth, (int) $args['id']);

        $recipes = $this->recipes->findMany($this->categories->recipeIds($category['id']));

        return $this->json($response, ['data' => array_values($recipes)]);
    }

    public function addRecipe(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $category = $this->requireOwnCategory($auth, (int) $args['id']);

        $recipeId = (int) $args['recipeId'];
        if ($this->recipes->find($recipeId) === null) {
            throw new NotFoundException('Recipe not found.');
        }

        $this->categories->addRecipe($category['id'], $recipeId);

        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function removeRecipe(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $category = $this->requireOwnCategory($auth, (int) $args['id']);

        $this->categories->removeRecipe($category['id'], (int) $args['recipeId']);

        return $this->json($response, ['data' => ['ok' => true]]);
    }

    private function requireOwnCategory(array $auth, int $id): array
    {
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new NotFoundException('Category not found.');
        }
        $this->assertOwnerOrAdmin($auth, $category['user_id']);

        return $category;
    }
}
