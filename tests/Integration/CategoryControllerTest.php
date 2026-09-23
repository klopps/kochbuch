<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\Category\CategoryRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Http\Controllers\CategoryController;

final class CategoryControllerTest extends ControllerTestCase
{
    private CategoryController $controller;
    private RecipeRepository $recipes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipes = new RecipeRepository($this->pdo);
        $this->controller = new CategoryController(new CategoryRepository($this->pdo), $this->recipes);
    }

    private function createRecipe(int $userId): int
    {
        return $this->recipes->create($userId, [
            'name' => 'Test Recipe',
            'description' => null,
            'servings' => 4,
            'difficulty' => 'normal',
            'prep_time_minutes' => null,
            'rest_time_minutes' => null,
            'cook_time_minutes' => null,
            'calories' => null,
            'allergen_info' => null,
            'is_vegan' => false,
            'is_vegetarian' => false,
            'is_pescetarian' => false,
            'source' => null,
            'source_url' => null,
            'visibility' => 'public',
            'notes' => null,
            'ingredients' => [],
            'steps' => [],
            'tags' => [],
        ]);
    }

    public function testCreateCategoryAndAddARecipeToIt(): void
    {
        $userId = $this->createUser();
        $recipeId = $this->createRecipe($userId);

        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/categories', authPayload: $this->authPayload($userId), jsonBody: ['name' => 'Favorites']),
            $this->response()
        ));
        $this->assertSame(201, $created['status']);
        $categoryId = $created['data']['id'];

        $this->controller->addRecipe(
            $this->request('PUT', "/api/v1/categories/$categoryId/recipes/$recipeId", authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $categoryId, 'recipeId' => (string) $recipeId]
        );

        $recipes = $this->decode($this->controller->recipes(
            $this->request('GET', "/api/v1/categories/$categoryId/recipes", authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $categoryId]
        ));
        $this->assertCount(1, $recipes['data']);
        $this->assertSame($recipeId, $recipes['data'][0]['id']);

        $index = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/categories', authPayload: $this->authPayload($userId), queryParams: ['recipe_id' => (string) $recipeId]),
            $this->response()
        ));
        $this->assertTrue($index['data'][0]['contains_recipe']);
    }

    public function testRemoveRecipeFromCategory(): void
    {
        $userId = $this->createUser();
        $recipeId = $this->createRecipe($userId);
        $categoryId = (new CategoryRepository($this->pdo))->create($userId, 'Favorites');
        (new CategoryRepository($this->pdo))->addRecipe($categoryId, $recipeId);

        $this->controller->removeRecipe(
            $this->request('DELETE', "/api/v1/categories/$categoryId/recipes/$recipeId", authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $categoryId, 'recipeId' => (string) $recipeId]
        );

        $recipes = $this->decode($this->controller->recipes(
            $this->request('GET', "/api/v1/categories/$categoryId/recipes", authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $categoryId]
        ));
        $this->assertCount(0, $recipes['data']);
    }

    public function testNonOwnerCannotModifyAnotherUsersCategory(): void
    {
        $ownerId = $this->createUser();
        $categoryId = (new CategoryRepository($this->pdo))->create($ownerId, 'Favorites');
        $otherId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->update(
            $this->request('PUT', "/api/v1/categories/$categoryId", authPayload: $this->authPayload($otherId), jsonBody: ['name' => 'Hijacked']),
            $this->response(),
            ['id' => (string) $categoryId]
        );
    }
}
