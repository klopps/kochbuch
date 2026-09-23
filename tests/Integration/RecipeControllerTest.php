<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Http\Controllers\RecipeController;
use Kochbuch\Service\RecipeImageService;

final class RecipeControllerTest extends ControllerTestCase
{
    private RecipeController $controller;
    private RecipeRepository $recipes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipes = new RecipeRepository($this->pdo);
        $this->controller = new RecipeController($this->recipes, new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Soup',
            'description' => 'A soup for testing',
            'servings' => 4,
            'difficulty' => 'normal',
            'prep_time_minutes' => 10,
            'rest_time_minutes' => null,
            'cook_time_minutes' => 20,
            'calories' => 300,
            'allergen_info' => null,
            'is_vegan' => true,
            'is_vegetarian' => false,
            'is_pescetarian' => false,
            'source' => null,
            'source_url' => null,
            'visibility' => 'public',
            'notes' => null,
            'ingredients' => [
                ['name' => 'Carrot', 'amount' => 400, 'unit' => 'g', 'note' => null],
            ],
            'steps' => ['Chop.', 'Cook.'],
            'tags' => ['soup', 'easy'],
        ], $overrides);
    }

    public function testCreateAndShowARecipe(): void
    {
        $userId = $this->createUser();

        $response = $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        );
        $result = $this->decode($response);

        $this->assertSame(201, $result['status']);
        $this->assertSame('Test Soup', $result['data']['name']);
        $this->assertCount(1, $result['data']['ingredients']);
        // assertEquals, not assertSame: RecipeRepository casts this to a PHP
        // float, but json_encode() renders a whole-number float as "400"
        // (no decimal point) and decode() below re-decodes it as a plain
        // int - a real HTTP response round-trip has the same behavior, so
        // this isn't a bug to "fix", just not an int-vs-float-safe value.
        $this->assertEquals(400, $result['data']['ingredients'][0]['amount']);
        $this->assertSame(['easy', 'soup'], $result['data']['tags']);

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $result['data']['id']),
            $this->response(),
            ['id' => (string) $result['data']['id']]
        ));
        $this->assertSame(200, $show['status']);
        $this->assertSame('Test Soup', $show['data']['name']);
    }

    public function testDefaultVisibilityIsInternalWhenNotSpecified(): void
    {
        $userId = $this->createUser();
        $payload = $this->payload();
        unset($payload['visibility']);

        $result = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $payload),
            $this->response()
        ));

        $this->assertSame('internal', $result['data']['visibility']);
    }

    public function testInternalRecipeIsVisibleToAnyLoggedInUserButNotAnonymous(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['visibility' => 'internal'])),
            $this->response()
        ));

        $otherId = $this->createUser();
        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id'], authPayload: $this->authPayload($otherId)),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));
        $this->assertSame(200, $show['status']);

        $this->expectException(ForbiddenException::class);
        $this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testSearchIncludesInternalRecipesOnlyWhenLoggedIn(): void
    {
        $ownerId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['name' => 'Internal Stew', 'visibility' => 'internal'])),
            $this->response()
        );

        $loggedInResult = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($this->createUser())),
            $this->response()
        ));
        $this->assertContains('Internal Stew', array_column($loggedInResult['data']['items'], 'name'));

        $anonymousResult = $this->decode($this->controller->index($this->request('GET', '/api/v1/recipes'), $this->response()));
        $this->assertNotContains('Internal Stew', array_column($anonymousResult['data']['items'], 'name'));
    }

    public function testPrivateRecipeIsNotVisibleToAnotherUser(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['visibility' => 'private'])),
            $this->response()
        ));

        $otherId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id'], authPayload: $this->authPayload($otherId)),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testOwnerCanUpdateTheirRecipe(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $updated = $this->decode($this->controller->update(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'], authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Renamed Soup', 'servings' => 6])),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame(200, $updated['status']);
        $this->assertSame('Renamed Soup', $updated['data']['name']);
        $this->assertSame(6, $updated['data']['servings']);
    }

    public function testNonOwnerCannotUpdateTheRecipe(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));

        $otherId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->update(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'], authPayload: $this->authPayload($otherId), jsonBody: $this->payload(['name' => 'Hijacked'])),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testCreateRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->create($this->request('POST', '/api/v1/recipes', jsonBody: $this->payload()), $this->response());
    }

    public function testOwnerCanDeleteTheirRecipe(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $response = $this->controller->delete(
            $this->request('DELETE', '/api/v1/recipes/' . $created['data']['id'], authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testSearchFiltersByQueryAndDiet(): void
    {
        $userId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Vegan Carrot Soup', 'is_vegan' => true])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Beef Stew', 'is_vegan' => false])),
            $this->response()
        );

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', queryParams: ['vegan' => '1']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertContains('Vegan Carrot Soup', $names);
        $this->assertNotContains('Beef Stew', $names);
    }

    /**
     * todo.md "Suche": name, description AND tags, since a recipe's tags
     * often carry search-relevant terms (e.g. a cuisine) that never appear
     * in its name or description.
     */
    public function testSearchMatchesTagsNotJustNameOrDescription(): void
    {
        $userId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                'name' => 'Grandma\'s Stew',
                'description' => 'A cozy family recipe.',
                'tags' => ['hungarian', 'comfort-food'],
            ])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Plain Rice', 'tags' => ['side-dish']])),
            $this->response()
        );

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', queryParams: ['q' => 'hungarian']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertContains('Grandma\'s Stew', $names);
        $this->assertNotContains('Plain Rice', $names);
    }

    public function testCategoryFilterOnlyMatchesRequestingUsersOwnCategory(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['name' => 'Categorized Soup'])),
            $this->response()
        ));

        $categories = new \Kochbuch\Domain\Category\CategoryRepository($this->pdo);
        $ownerCategoryId = $categories->create($ownerId, 'Suppen');
        $categories->addRecipe($ownerCategoryId, (int) $created['data']['id']);

        $otherId = $this->createUser();
        $categories->create($otherId, 'Andere Kategorie');

        $resultWithOwnCategory = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), queryParams: ['category_id' => (string) $ownerCategoryId]),
            $this->response()
        ));
        $this->assertContains('Categorized Soup', array_column($resultWithOwnCategory['data']['items'], 'name'));

        $resultWithForeignCategoryId = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($otherId), queryParams: ['category_id' => (string) $ownerCategoryId]),
            $this->response()
        ));
        $this->assertSame([], $resultWithForeignCategoryId['data']['items']);
    }

    public function testUncategorizedMineFilterShowsOnlyOwnUncategorizedRecipes(): void
    {
        $userId = $this->createUser();
        $uncategorized = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Uncategorized Soup'])),
            $this->response()
        ));
        $categorized = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Categorized Soup'])),
            $this->response()
        ));

        $categories = new \Kochbuch\Domain\Category\CategoryRepository($this->pdo);
        $categoryId = $categories->create($userId, 'Suppen');
        $categories->addRecipe($categoryId, (int) $categorized['data']['id']);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['category_id' => 'uncategorized']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertContains('Uncategorized Soup', $names);
        $this->assertNotContains('Categorized Soup', $names);
    }

    public function testSearchResultsArePaginated(): void
    {
        $userId = $this->createUser();
        for ($i = 1; $i <= 12; $i++) {
            $this->controller->create(
                $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Paginated Recipe ' . $i])),
                $this->response()
            );
        }

        $firstPage = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'per_page' => '10', 'page' => '1']),
            $this->response()
        ));
        $this->assertCount(10, $firstPage['data']['items']);
        $this->assertSame(12, $firstPage['data']['total']);
        $this->assertSame(1, $firstPage['data']['page']);
        $this->assertSame(10, $firstPage['data']['per_page']);

        $secondPage = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'per_page' => '10', 'page' => '2']),
            $this->response()
        ));
        $this->assertCount(2, $secondPage['data']['items']);

        $invalidPageSize = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'per_page' => '999']),
            $this->response()
        ));
        $this->assertSame(10, $invalidPageSize['data']['per_page']);
    }
}
