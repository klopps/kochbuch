<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\User\UserRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\QuickEditController;
use Kochbuch\Http\Controllers\RecipeController;
use Kochbuch\Service\RecipeImageService;

/**
 * todo.md "Quick Editor": single-field changes from the admin list, and the
 * admin list's owner filter.
 */
final class QuickEditControllerTest extends ControllerTestCase
{
    private RecipeRepository $recipes;
    private QuickEditController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipes = new RecipeRepository($this->pdo, new PlaceholderImageRepository($this->pdo));
        $this->controller = new QuickEditController($this->recipes, new UserRepository($this->pdo));
    }

    private function createRecipe(int $userId, string $name): int
    {
        return $this->recipes->create($userId, [
            'name' => $name, 'description' => null, 'servings' => 4, 'difficulty' => 'normal',
            'prep_time_minutes' => null, 'rest_time_minutes' => null, 'cook_time_minutes' => null, 'calories' => null,
            'allergen_info' => null, 'is_vegan' => true, 'is_vegetarian' => false, 'is_pescetarian' => false,
            'source' => null, 'source_url' => null, 'visibility' => 'internal', 'notes' => null,
            'ingredients' => [['name' => 'Mehl', 'amount' => 200, 'unit' => 'g', 'note' => null]],
            'steps' => [['instruction' => 'Backen.', 'is_heading' => false]],
            'tags' => ['kuchen'],
        ]);
    }

    private function quick(int $adminId, int $recipeId, array $body, bool $admin = true): array
    {
        return $this->decode($this->controller->update(
            $this->request('PUT', '/api/v1/admin/recipes/' . $recipeId . '/quick', authPayload: $this->authPayload($adminId, ['is_admin' => $admin]), jsonBody: $body),
            $this->response(),
            ['id' => (string) $recipeId]
        ));
    }

    public function testChangesOnlyTheSentFieldsAndKeepsIngredientsStepsTags(): void
    {
        $adminId = $this->createUser();
        $newOwner = $this->createUser();
        $id = $this->createRecipe($adminId, 'Kuchen');

        $this->quick($adminId, $id, ['name' => '  Apfelkuchen ', 'servings' => 8]);
        $this->quick($adminId, $id, ['diet' => 'vegetarian', 'visibility' => 'public']);
        $result = $this->quick($adminId, $id, ['owner_id' => $newOwner]);

        $recipe = $result['data'];
        $this->assertSame('Apfelkuchen', $recipe['name']);
        $this->assertSame(8, $recipe['servings']);
        $this->assertFalse($recipe['is_vegan']);
        $this->assertTrue($recipe['is_vegetarian']);
        $this->assertSame('public', $recipe['visibility']);
        $this->assertSame($newOwner, $recipe['user_id']);
        $this->assertSame('Mehl', $recipe['ingredients'][0]['name']);
        $this->assertSame('Backen.', $recipe['steps'][0]['instruction']);
        $this->assertSame(['kuchen'], $recipe['tags']);

        $none = $this->quick($adminId, $id, ['diet' => 'none'])['data'];
        $this->assertFalse($none['is_vegan'] || $none['is_vegetarian'] || $none['is_pescetarian']);
    }

    public function testRejectsInvalidValues(): void
    {
        $adminId = $this->createUser();
        $id = $this->createRecipe($adminId, 'Kuchen');

        foreach ([
            [['name' => '  '], 'recipe.name_required'],
            [['servings' => 0], 'recipe.invalid_servings'],
            [['visibility' => 'secret'], 'recipe.invalid_visibility'],
            [['diet' => 'carnivore'], 'recipe.invalid_diet'],
            [['owner_id' => 999999], 'quick_edit.owner_invalid'],
        ] as [$body, $code]) {
            try {
                $this->quick($adminId, $id, $body);
                $this->fail('Expected ' . $code);
            } catch (ValidationException $e) {
                $this->assertSame($code, $e->getErrorCode());
            }
        }
        $this->assertSame('Kuchen', $this->recipes->find($id)['name']);
    }

    public function testRequiresAnAdmin(): void
    {
        $userId = $this->createUser();
        $id = $this->createRecipe($userId, 'Kuchen');

        $this->expectException(ForbiddenException::class);
        $this->quick($userId, $id, ['name' => 'X'], false);
    }

    public function testAdminListCanShowOnlyRecipesWithoutDiet(): void
    {
        $adminId = $this->createUser();
        $vegan = $this->createRecipe($adminId, 'Vegan');           // createRecipe() makes it vegan
        $plain = $this->createRecipe($adminId, 'Ohne Typ');
        $this->recipes->quickUpdate($plain, ['is_vegan' => false]);
        $controller = new RecipeController($this->recipes, new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'));

        $result = $this->decode($controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), queryParams: ['owner_id' => (string) $adminId, 'no_diet' => '1']),
            $this->response()
        ));

        $this->assertSame(['Ohne Typ'], array_column($result['data']['items'], 'name'));
        $this->assertNotSame($vegan, $plain);
    }

    public function testAdminListCanBeFilteredByOwner(): void
    {
        $adminId = $this->createUser();
        $other = $this->createUser();
        $this->createRecipe($adminId, 'Meins');
        $this->createRecipe($other, 'Fremd');
        $controller = new RecipeController($this->recipes, new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'));

        $result = $this->decode($controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), queryParams: ['owner_id' => (string) $other]),
            $this->response()
        ));

        $this->assertSame(['Fremd'], array_column($result['data']['items'], 'name'));
    }
}
