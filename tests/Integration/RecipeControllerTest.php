<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ApiException;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\RecipeController;
use Kochbuch\Service\BringService;
use Kochbuch\Service\RecipeImageService;
use Kochbuch\Service\VisionOcrService;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class RecipeControllerTest extends ControllerTestCase
{
    private RecipeController $controller;
    private RecipeRepository $recipes;
    private PlaceholderImageRepository $placeholderImages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->placeholderImages = new PlaceholderImageRepository($this->pdo);
        $this->recipes = new RecipeRepository($this->pdo, $this->placeholderImages);
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
            'steps' => [
                ['instruction' => 'Chop.', 'is_heading' => false],
                ['instruction' => 'Cook.', 'is_heading' => false],
            ],
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

    /**
     * todo.md "Displaying the recipe author" - show() (unlike the list
     * endpoints) joins in the owner's username so the frontend can render
     * "by <username>" under the recipe name.
     */
    public function testShowIncludesTheOwnersUsername(): void
    {
        $userId = $this->createUser(['username' => 'chef']);

        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame('chef', $show['data']['owner_username']);
    }

    public function testCreateRejectsMoreThanOneDietFlag(): void
    {
        $userId = $this->createUser();

        try {
            $this->controller->create(
                $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                    'is_vegan' => true,
                    'is_vegetarian' => true,
                ])),
                $this->response()
            );
            $this->fail('Expected a recipe.ambiguous_diet ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ambiguous_diet', $e->getErrorCode());
        }
    }

    public function testIngredientHeadingRowIgnoresAmountUnitAndNote(): void
    {
        $userId = $this->createUser();

        $result = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                'ingredients' => [
                    ['name' => 'Sauce', 'is_heading' => true, 'amount' => 400, 'unit' => 'g', 'note' => 'ignored'],
                    ['name' => 'Sahne', 'amount' => 50, 'unit' => 'g', 'note' => 'kalt'],
                ],
            ])),
            $this->response()
        ));

        $this->assertCount(2, $result['data']['ingredients']);
        $heading = $result['data']['ingredients'][0];
        $this->assertTrue($heading['is_heading']);
        $this->assertSame('Sauce', $heading['name']);
        $this->assertNull($heading['amount']);
        $this->assertNull($heading['unit']);
        $this->assertNull($heading['note']);

        $ingredient = $result['data']['ingredients'][1];
        $this->assertFalse($ingredient['is_heading']);
        $this->assertEquals(50, $ingredient['amount']);
        $this->assertSame('g', $ingredient['unit']);
        $this->assertSame('kalt', $ingredient['note']);
    }

    public function testStepHeadingRoundTripsAndBlankStepsAreDropped(): void
    {
        $userId = $this->createUser();

        $result = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                'steps' => [
                    ['instruction' => 'Sauce', 'is_heading' => true],
                    ['instruction' => 'Simmer.', 'is_heading' => false],
                    ['instruction' => '   ', 'is_heading' => false],
                ],
            ])),
            $this->response()
        ));

        $this->assertCount(2, $result['data']['steps']);
        $this->assertTrue($result['data']['steps'][0]['is_heading']);
        $this->assertSame('Sauce', $result['data']['steps'][0]['instruction']);
        $this->assertFalse($result['data']['steps'][1]['is_heading']);
        $this->assertSame('Simmer.', $result['data']['steps'][1]['instruction']);
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

    private function controllerWithFakeBring(callable $sender, string $appUrl = 'https://kochbuch.example.test'): RecipeController
    {
        return new RecipeController(
            $this->recipes,
            new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'),
            10,
            new BringService('https://api.getbring.invalid/deeplink', $sender),
            $appUrl
        );
    }

    public function testBringExportRequiresVisibility(): void
    {
        $ownerId = $this->createUser();
        $otherId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['visibility' => 'private'])),
            $this->response()
        ));

        $this->expectException(ForbiddenException::class);
        $this->controller->bringExport(
            $this->request('POST', '/api/v1/recipes/' . $created['data']['id'] . '/bring-export', authPayload: $this->authPayload($otherId), jsonBody: ['requested_servings' => 4]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testBringExportReturnsADeeplinkAndCreatesAToken(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['visibility' => 'private'])),
            $this->response()
        ));
        $recipeId = $created['data']['id'];

        $capturedUrl = null;
        $controller = $this->controllerWithFakeBring(function (string $apiUrl, array $payload) use (&$capturedUrl) {
            $capturedUrl = $payload['url'];

            return ['status' => 200, 'body' => json_encode(['deeplink' => 'https://getbring.example/test-deeplink'])];
        });

        $result = $this->decode($controller->bringExport(
            $this->request('POST', '/api/v1/recipes/' . $recipeId . '/bring-export', authPayload: $this->authPayload($userId), jsonBody: ['requested_servings' => 8]),
            $this->response(),
            ['id' => (string) $recipeId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('https://getbring.example/test-deeplink', $result['data']['deeplink']);
        $this->assertStringStartsWith('https://kochbuch.example.test/bring-export/', $capturedUrl);

        // The link works for a *private* recipe - proof that Bring!'s
        // later fetch only checks token possession, not normal visibility.
        $token = substr($capturedUrl, strlen('https://kochbuch.example.test/bring-export/'));
        $this->assertSame($recipeId, $this->recipes->findRecipeIdForValidBringExportToken($token));
    }

    public function testBringExportThrowsWhenBringIsUnavailable(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $controller = $this->controllerWithFakeBring(fn () => ['status' => 500, 'body' => 'oops']);

        try {
            $controller->bringExport(
                $this->request('POST', '/api/v1/recipes/' . $created['data']['id'] . '/bring-export', authPayload: $this->authPayload($userId), jsonBody: []),
                $this->response(),
                ['id' => (string) $created['data']['id']]
            );
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('recipe.bring_unavailable', $e->getErrorCode());
        }
    }

    public function testRenderBringExportPageIncludesSchemaOrgMarkupAndExcludesHeadings(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                'visibility' => 'private',
                'servings' => 4,
                'ingredients' => [
                    ['name' => 'Sauce', 'is_heading' => true],
                    ['name' => 'Carrot', 'amount' => 400, 'unit' => 'g', 'note' => null],
                ],
            ])),
            $this->response()
        ));

        $token = $this->recipes->createBringExportToken($created['data']['id'], 600);
        $html = $this->controller->renderBringExportPage(dirname(__DIR__, 2), $token);

        $this->assertNotNull($html);
        $this->assertStringContainsString('itemtype="http://schema.org/Recipe"', $html);
        $this->assertStringContainsString('itemprop="recipeYield" content="4"', $html);
        $this->assertStringContainsString('400 g Carrot', $html);
        $this->assertStringNotContainsString('Sauce', $html);
    }

    public function testRenderBringExportPageReturnsNullForAnUnknownToken(): void
    {
        $this->assertNull($this->controller->renderBringExportPage(dirname(__DIR__, 2), 'does-not-exist'));
    }

    public function testHomeHidesLatestSectionForGuestsAndShowsOnlyPublicInRandom(): void
    {
        $userId = $this->createUser();
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Private One', 'visibility' => 'private'])), $this->response());
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Internal One', 'visibility' => 'internal'])), $this->response());
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Public One', 'visibility' => 'public'])), $this->response());

        $result = $this->decode($this->controller->home($this->request('GET', '/api/v1/home'), $this->response()));

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['data']['latest']);
        $randomNames = array_column($result['data']['random']['items'], 'name');
        $this->assertContains('Public One', $randomNames);
        $this->assertNotContains('Private One', $randomNames);
        $this->assertNotContains('Internal One', $randomNames);
    }

    public function testHomeShowsOwnPrivateAndAnyInternalRecipesInLatestForALoggedInUser(): void
    {
        $ownerId = $this->createUser();
        $otherId = $this->createUser();
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['name' => 'My Private', 'visibility' => 'private'])), $this->response());
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($otherId), jsonBody: $this->payload(['name' => 'Someone Elses Private', 'visibility' => 'private'])), $this->response());
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($otherId), jsonBody: $this->payload(['name' => 'Anyones Internal', 'visibility' => 'internal'])), $this->response());
        $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($otherId), jsonBody: $this->payload(['name' => 'Public Import', 'visibility' => 'public'])), $this->response());

        $result = $this->decode($this->controller->home($this->request('GET', '/api/v1/home', authPayload: $this->authPayload($ownerId)), $this->response()));

        $latestNames = array_column($result['data']['latest'], 'name');
        $this->assertContains('My Private', $latestNames);
        $this->assertContains('Anyones Internal', $latestNames);
        $this->assertNotContains('Someone Elses Private', $latestNames);
        $this->assertNotContains('Public Import', $latestNames);

        // "Random Recipes" must never repeat whatever "Latest Recipes"
        // already shows.
        $randomNames = array_column($result['data']['random']['items'], 'name');
        $this->assertContains('Public Import', $randomNames);
        $this->assertNotContains('My Private', $randomNames);
        $this->assertNotContains('Anyones Internal', $randomNames);
    }

    public function testHomeLatestIsCappedAtSixRecipesByDefault(): void
    {
        $userId = $this->createUser();
        for ($i = 1; $i <= 8; $i++) {
            $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Internal ' . $i, 'visibility' => 'internal'])), $this->response());
        }

        $result = $this->decode($this->controller->home($this->request('GET', '/api/v1/home', authPayload: $this->authPayload($userId)), $this->response()));

        $this->assertCount(6, $result['data']['latest']);
    }

    /**
     * todo.md "Last recipies configurable" - the cap itself is admin-
     * configurable (setting "home_latest_recipes_count", wired in App.php),
     * not just the hardcoded 6 covered above.
     */
    public function testHomeLatestRecipesLimitIsConfigurable(): void
    {
        $userId = $this->createUser();
        for ($i = 1; $i <= 8; $i++) {
            $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Internal ' . $i, 'visibility' => 'internal'])), $this->response());
        }

        $controller = new RecipeController($this->recipes, new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'), 10, new BringService(), '', 3);
        $result = $this->decode($controller->home($this->request('GET', '/api/v1/home', authPayload: $this->authPayload($userId)), $this->response()));

        $this->assertCount(3, $result['data']['latest']);
    }

    public function testHomeGeneratesAndReturnsASeedWhenNoneProvided(): void
    {
        $result = $this->decode($this->controller->home($this->request('GET', '/api/v1/home'), $this->response()));

        $this->assertIsInt($result['data']['seed']);
        $this->assertGreaterThan(0, $result['data']['seed']);
    }

    public function testHomeSameSeedProducesTheSameRandomOrderAcrossRequests(): void
    {
        $userId = $this->createUser();
        for ($i = 1; $i <= 5; $i++) {
            $this->controller->create($this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Random Candidate ' . $i, 'visibility' => 'public'])), $this->response());
        }

        $first = $this->decode($this->controller->home($this->request('GET', '/api/v1/home', queryParams: ['seed' => '42']), $this->response()));
        $second = $this->decode($this->controller->home($this->request('GET', '/api/v1/home', queryParams: ['seed' => '42']), $this->response()));

        $this->assertSame('42', (string) $first['data']['seed']);
        $this->assertSame(
            array_column($first['data']['random']['items'], 'id'),
            array_column($second['data']['random']['items'], 'id')
        );
    }

    public function testFindRecipeIdForValidBringExportTokenReturnsNullOnceExpired(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        // A negative TTL puts expires_at in the past immediately - no need
        // to sleep in the test to observe expiry.
        $token = $this->recipes->createBringExportToken($created['data']['id'], -1);

        $this->assertNull($this->recipes->findRecipeIdForValidBringExportToken($token));
        $this->assertNull($this->controller->renderBringExportPage(dirname(__DIR__, 2), $token));
    }

    private function controllerWithFakeVision(callable $sender): RecipeController
    {
        return new RecipeController(
            $this->recipes,
            new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'),
            visionOcr: new VisionOcrService('fake-key', $sender),
        );
    }

    private function fakeUploadedImage(?string $bytes = null, string $mime = 'image/png'): UploadedFile
    {
        $bytes ??= $this->tinyPngBytes();
        $stream = (new StreamFactory())->createStream($bytes);

        return new UploadedFile($stream, 'photo.png', $mime, strlen($bytes), UPLOAD_ERR_OK);
    }

    private function tinyPngBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function testOcrRequiresAuth(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->ocr($this->request('POST', '/api/v1/recipes/ocr'), $this->response());
    }

    public function testOcrRejectsRequestWithNoImages(): void
    {
        $userId = $this->createUser();

        try {
            $this->controller->ocr($this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId)), $this->response());
            $this->fail('Expected a recipe.image_missing ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.image_missing', $e->getErrorCode());
        }
    }

    public function testOcrRejectsMoreThanEightImages(): void
    {
        $userId = $this->createUser();
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => array_fill(0, 9, $this->fakeUploadedImage())]);

        try {
            $this->controller->ocr($request, $this->response());
            $this->fail('Expected a recipe.too_many_images ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.too_many_images', $e->getErrorCode());
        }
    }

    public function testOcrRejectsAnOversizedImage(): void
    {
        $userId = $this->createUser();
        $oversized = new UploadedFile((new StreamFactory())->createStream($this->tinyPngBytes()), 'photo.png', 'image/png', 6 * 1024 * 1024, UPLOAD_ERR_OK);
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$oversized]]);

        try {
            $this->controller->ocr($request, $this->response());
            $this->fail('Expected a recipe.image_too_large ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.image_too_large', $e->getErrorCode());
        }
    }

    public function testOcrRejectsANonImageFile(): void
    {
        $userId = $this->createUser();
        $textFile = $this->fakeUploadedImage('this is not an image', 'image/png');
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$textFile]]);

        try {
            $this->controller->ocr($request, $this->response());
            $this->fail('Expected a recipe.image_invalid_type ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.image_invalid_type', $e->getErrorCode());
        }
    }

    public function testOcrReturnsAParsedDraftFromTheRecognizedText(): void
    {
        $userId = $this->createUser();
        $controller = $this->controllerWithFakeVision(function () {
            return [
                'status' => 200,
                'body' => json_encode(['responses' => [['fullTextAnnotation' => ['text' => "Apfelkuchen\n\nZutaten:\n200 g Mehl\n\nZubereitung:\nBacken."]]]]),
            ];
        });
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$this->fakeUploadedImage()]]);

        $result = $this->decode($controller->ocr($request, $this->response()));

        $this->assertSame('Apfelkuchen', $result['data']['name']);
        $this->assertSame('Mehl', $result['data']['ingredients'][0]['name']);
        $this->assertSame('Backen.', $result['data']['steps'][0]['instruction']);
    }

    public function testOcrMergesIngredientsAndStepsParsedFromMultipleImages(): void
    {
        // Each photo is parsed on its own (RecipeOcrParser needs a single
        // page's own paragraph geometry to tell two printed columns apart -
        // see its class docblock), then the per-page drafts are merged -
        // covers a common real case: one photo per recipe-card page.
        $userId = $this->createUser();
        $calls = 0;
        $controller = $this->controllerWithFakeVision(function () use (&$calls) {
            $calls++;
            $text = $calls === 1
                ? "Apfelkuchen\n\nZutaten:\n200 g Mehl\n\nZubereitung:\nRühren."
                : "Zutaten:\n1 Ei\n\nZubereitung:\nMischen.";

            return ['status' => 200, 'body' => json_encode(['responses' => [['fullTextAnnotation' => ['text' => $text]]]])];
        });
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$this->fakeUploadedImage(), $this->fakeUploadedImage()]]);

        $result = $this->decode($controller->ocr($request, $this->response()));

        $this->assertSame(2, $calls);
        $this->assertSame('Apfelkuchen', $result['data']['name']);
        $this->assertSame(['Mehl', 'Ei'], array_column($result['data']['ingredients'], 'name'));
        $this->assertSame(['Rühren.', 'Mischen.'], array_column($result['data']['steps'], 'instruction'));
        $this->assertStringContainsString('---', $result['data']['raw_text']);
    }

    public function testOcrSurfacesAClearErrorWhenVisionIsUnavailable(): void
    {
        $userId = $this->createUser();
        $controller = $this->controllerWithFakeVision(function () {
            return ['status' => 500, 'body' => 'oops'];
        });
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$this->fakeUploadedImage()]]);

        try {
            $controller->ocr($request, $this->response());
            $this->fail('Expected a recipe.ocr_unavailable ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('recipe.ocr_unavailable', $e->getErrorCode());
        }
    }

    private function fakeUploadedJson(string $json, string $filename = 'recipe.json'): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($json), $filename, 'application/json', strlen($json), UPLOAD_ERR_OK);
    }

    public function testImportJsonRequiresAuth(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->importJson($this->request('POST', '/api/v1/recipes/import-json'), $this->response());
    }

    public function testImportJsonRejectsRequestWithNoFiles(): void
    {
        $userId = $this->createUser();

        try {
            $this->controller->importJson($this->request('POST', '/api/v1/recipes/import-json', authPayload: $this->authPayload($userId)), $this->response());
            $this->fail('Expected a recipe.import_json_missing ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.import_json_missing', $e->getErrorCode());
        }
    }

    public function testImportJsonRejectsMoreThanTwentyFiles(): void
    {
        $userId = $this->createUser();
        $request = $this->request('POST', '/api/v1/recipes/import-json', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['files' => array_fill(0, 21, $this->fakeUploadedJson('{}'))]);

        try {
            $this->controller->importJson($request, $this->response());
            $this->fail('Expected a recipe.import_json_too_many_files ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.import_json_too_many_files', $e->getErrorCode());
        }
    }

    public function testImportJsonCreatesARecipeFromAValidSchemaOrgFile(): void
    {
        $userId = $this->createUser();
        $json = json_encode([
            'name' => 'Apfelkuchen',
            'recipeIngredient' => ['200 g Mehl', '3 Eier'],
            'recipeInstructions' => ['Mehl und Eier verrühren.', 'Backen bei 180 Grad.'],
        ]);
        $request = $this->request('POST', '/api/v1/recipes/import-json', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['files' => [$this->fakeUploadedJson($json, 'apfelkuchen.json')]]);

        $result = $this->decode($this->controller->importJson($request, $this->response()));

        $this->assertCount(1, $result['data']['results']);
        $this->assertSame('created', $result['data']['results'][0]['status']);
        $this->assertSame('apfelkuchen.json', $result['data']['results'][0]['filename']);
        $this->assertSame('Apfelkuchen', $result['data']['results'][0]['name']);

        $created = $this->recipes->find($result['data']['results'][0]['id']);
        $this->assertSame('Apfelkuchen', $created['name']);
        $this->assertSame('Mehl', $created['ingredients'][0]['name']);
        $this->assertSame('Backen bei 180 Grad.', $created['steps'][1]['instruction']);
        $this->assertSame($userId, $created['user_id']);
    }

    public function testImportJsonProcessesEachFileIndependentlyOneBadFileDoesNotLoseTheOthers(): void
    {
        $userId = $this->createUser();
        $good = json_encode(['name' => 'Gutes Rezept', 'recipeIngredient' => ['1 Ei']]);
        $request = $this->request('POST', '/api/v1/recipes/import-json', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['files' => [
                $this->fakeUploadedJson('not valid json', 'broken.json'),
                $this->fakeUploadedJson($good, 'good.json'),
                $this->fakeUploadedJson(json_encode(['description' => 'kein Name']), 'no-name.json'),
            ]]);

        $result = $this->decode($this->controller->importJson($request, $this->response()));
        $results = $result['data']['results'];

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('invalid_json', $results[0]['error_code']);
        $this->assertSame('created', $results[1]['status']);
        $this->assertSame('error', $results[2]['status']);
        $this->assertSame('missing_name', $results[2]['error_code']);
    }

    public function testImportJsonDownloadsAndAttachesTheImageWhenPresent(): void
    {
        // A data: URI needs no real network call (file_get_contents()
        // handles it via PHP's built-in data:// stream wrapper) while still
        // exercising the actual download+store code path end to end.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $dataUri = 'data://image/png;base64,' . base64_encode($png);

        $userId = $this->createUser();
        $json = json_encode(['name' => 'Mit Bild', 'image' => $dataUri]);
        $request = $this->request('POST', '/api/v1/recipes/import-json', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['files' => [$this->fakeUploadedJson($json)]]);

        $result = $this->decode($this->controller->importJson($request, $this->response()));
        $created = $this->recipes->find($result['data']['results'][0]['id']);

        $this->assertCount(1, $created['images']);
    }

    public function testAdminIndexRequiresAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($userId)),
            $this->response()
        );
    }

    public function testAdminIndexBypassesVisibilityAndSeesEveryonesRecipes(): void
    {
        $ownerId = $this->createUser();
        $adminId = $this->createUser(['is_admin' => 1]);
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['name' => 'Someone Elses Private', 'visibility' => 'private'])),
            $this->response()
        );

        $result = $this->decode($this->controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertContains('Someone Elses Private', $names);
    }

    public function testAdminIndexStillHonorsHomepageStyleFilters(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: $this->payload(['name' => 'Easy One', 'difficulty' => 'easy'])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: $this->payload(['name' => 'Hard One', 'difficulty' => 'hard'])),
            $this->response()
        );

        $result = $this->decode($this->controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), queryParams: ['difficulty' => 'hard']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertSame(['Hard One'], $names);
    }

    public function testAdminIndexUntaggedFilterOnlyMatchesRecipesWithoutTags(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: $this->payload(['name' => 'Tagged One', 'tags' => ['soup']])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: $this->payload(['name' => 'Untagged One', 'tags' => []])),
            $this->response()
        );

        $result = $this->decode($this->controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), queryParams: ['untagged' => '1']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertSame(['Untagged One'], $names);
    }

    public function testUpdateTagsRequiresAdmin(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $this->expectException(ForbiddenException::class);
        $this->controller->updateTags(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/tags', authPayload: $this->authPayload($userId), jsonBody: ['tags' => ['whatever']]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testUpdateTagsThrowsNotFoundForAMissingRecipe(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $this->expectException(NotFoundException::class);
        $this->controller->updateTags(
            $this->request('PUT', '/api/v1/recipes/999999/tags', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: ['tags' => ['whatever']]),
            $this->response(),
            ['id' => '999999']
        );
    }

    public function testUpdateTagsReplacesOnlyTheTagsLeavingIngredientsAndStepsUntouched(): void
    {
        $userId = $this->createUser();
        $adminId = $this->createUser(['is_admin' => 1]);
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $result = $this->decode($this->controller->updateTags(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/tags', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: ['tags' => ['new-tag']]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame(['new-tag'], $result['data']['tags']);
        $this->assertSame('Test Soup', $result['data']['name']);
        $this->assertCount(1, $result['data']['ingredients']);
        $this->assertSame('Carrot', $result['data']['ingredients'][0]['name']);
        $this->assertCount(2, $result['data']['steps']);
    }

    public function testPlaceholderImageIsNullWhenTheRecipeHasARealImage(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));
        $this->recipes->addImage($created['data']['id'], 'whatever.png');

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertNull($show['data']['placeholder_image_filename']);
    }

    public function testPlaceholderImageMatchesByTagWhenTheRecipeHasNoImage(): void
    {
        $this->placeholderImages->create('cookie.png', false, [['locale' => 'de', 'keyword' => 'kekse']]);
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Untitled', 'tags' => ['kekse']])),
            $this->response()
        ));

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame('cookie.png', $show['data']['placeholder_image_filename']);
    }

    public function testPlaceholderImageMatchesByNameBeforeTags(): void
    {
        $this->placeholderImages->create('cookie.png', false, [['locale' => 'de', 'keyword' => 'kekse']]);
        $this->placeholderImages->create('fish.png', false, [['locale' => 'de', 'keyword' => 'fisch']]);
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Schokokekse', 'tags' => ['fisch']])),
            $this->response()
        ));

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame('cookie.png', $show['data']['placeholder_image_filename']);
    }

    public function testPlaceholderImageFallsBackToTheDefaultRowWhenNothingMatches(): void
    {
        $this->placeholderImages->create('cookie.png', false, [['locale' => 'de', 'keyword' => 'kekse']]);
        $this->placeholderImages->create('generic.png', true, []);
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Salat', 'tags' => []])),
            $this->response()
        ));

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame('generic.png', $show['data']['placeholder_image_filename']);
    }
}
