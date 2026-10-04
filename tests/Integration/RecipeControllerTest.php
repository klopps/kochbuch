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
use Kochbuch\Service\GeminiRecipeExtractor;
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

    public function testSearchAndShowReturnNullAverageRatingAndZeroCountBeforeAnyRating(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));
        $this->assertNull($show['data']['average_rating']);
        $this->assertSame(0, $show['data']['rating_count']);
        $this->assertNull($show['data']['my_rating']);

        $list = $this->decode($this->controller->index($this->request('GET', '/api/v1/recipes'), $this->response()));
        $listed = current(array_filter($list['data']['items'], static fn (array $r) => $r['id'] === $created['data']['id']));
        $this->assertNull($listed['average_rating']);
        $this->assertSame(0, $listed['rating_count']);
    }

    public function testRatingARecipeUpdatesTheAverageAndRatingCount(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));
        $recipeId = $created['data']['id'];

        $raterA = $this->createUser();
        $raterB = $this->createUser();

        $firstRating = $this->decode($this->controller->rate(
            $this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterA), jsonBody: ['rating' => 5]),
            $this->response(),
            ['id' => (string) $recipeId]
        ));
        $this->assertSame(200, $firstRating['status']);
        $this->assertEquals(5.0, $firstRating['data']['average_rating']);
        $this->assertSame(1, $firstRating['data']['rating_count']);
        $this->assertSame(5, $firstRating['data']['my_rating']);

        $secondRating = $this->decode($this->controller->rate(
            $this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterB), jsonBody: ['rating' => 1]),
            $this->response(),
            ['id' => (string) $recipeId]
        ));
        $this->assertEquals(3.0, $secondRating['data']['average_rating']);
        $this->assertSame(2, $secondRating['data']['rating_count']);
        $this->assertSame(1, $secondRating['data']['my_rating']);
    }

    /**
     * Re-rating the same recipe must update raterA's one existing row
     * (recipe_rating's composite primary key), not add a second one -
     * rating_count stays at 2, the average reflects the new value only.
     */
    public function testRatingTheSameRecipeAgainUpdatesTheExistingRatingInstead(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));
        $recipeId = $created['data']['id'];

        $raterA = $this->createUser();
        $raterB = $this->createUser();

        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterA), jsonBody: ['rating' => 5]), $this->response(), ['id' => (string) $recipeId]);
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterB), jsonBody: ['rating' => 1]), $this->response(), ['id' => (string) $recipeId]);

        $updated = $this->decode($this->controller->rate(
            $this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterA), jsonBody: ['rating' => 3]),
            $this->response(),
            ['id' => (string) $recipeId]
        ));

        $this->assertSame(2, $updated['data']['rating_count']);
        $this->assertEquals(2.0, $updated['data']['average_rating']);
        $this->assertSame(3, $updated['data']['my_rating']);
    }

    public function testDeletingARatingRemovesItFromTheAverage(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));
        $recipeId = $created['data']['id'];

        $raterA = $this->createUser();
        $raterB = $this->createUser();
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterA), jsonBody: ['rating' => 5]), $this->response(), ['id' => (string) $recipeId]);
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterB), jsonBody: ['rating' => 1]), $this->response(), ['id' => (string) $recipeId]);

        $result = $this->decode($this->controller->deleteRating(
            $this->request('DELETE', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($raterA)),
            $this->response(),
            ['id' => (string) $recipeId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['data']['my_rating']);
        $this->assertSame(1, $result['data']['rating_count']);
        $this->assertEquals(1.0, $result['data']['average_rating']);
    }

    /**
     * Deleting a rating that never existed is a no-op, not an error -
     * matches the idempotent-delete convention used elsewhere in this app.
     */
    public function testDeletingARatingThatNeverExistedIsANoOp(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));
        $recipeId = $created['data']['id'];

        $otherUser = $this->createUser();

        $result = $this->decode($this->controller->deleteRating(
            $this->request('DELETE', '/api/v1/recipes/' . $recipeId . '/rating', authPayload: $this->authPayload($otherUser)),
            $this->response(),
            ['id' => (string) $recipeId]
        ));

        $this->assertSame(200, $result['status']);
        $this->assertNull($result['data']['my_rating']);
        $this->assertSame(0, $result['data']['rating_count']);
    }

    public function testDeletingARatingRequiresAuthentication(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));

        $this->expectException(UnauthorizedException::class);
        $this->controller->deleteRating(
            $this->request('DELETE', '/api/v1/recipes/' . $created['data']['id'] . '/rating'),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    /**
     * @dataProvider invalidRatingProvider
     */
    public function testRatingOutsideOneToFiveIsRejected(int $invalidRating): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));

        try {
            $this->controller->rate(
                $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/rating', authPayload: $this->authPayload($ownerId), jsonBody: ['rating' => $invalidRating]),
                $this->response(),
                ['id' => (string) $created['data']['id']]
            );
            $this->fail('Expected a recipe.invalid_rating ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.invalid_rating', $e->getErrorCode());
        }
    }

    public static function invalidRatingProvider(): array
    {
        return [[0], [6], [-1]];
    }

    public function testRatingRequiresAuthentication(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));

        $this->expectException(UnauthorizedException::class);
        $this->controller->rate(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/rating', jsonBody: ['rating' => 4]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    /**
     * rate() must gate on the same visibility rules as show() (findVisible())
     * - a logged-in user who simply isn't allowed to see a private recipe
     * can't rate it either.
     */
    public function testRatingAPrivateRecipeYouCannotSeeIsForbidden(): void
    {
        $ownerId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload(['visibility' => 'private'])),
            $this->response()
        ));

        $otherId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->rate(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/rating', authPayload: $this->authPayload($otherId), jsonBody: ['rating' => 4]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
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

    /**
     * todo.md "Deleting Recipes" - delete() is a soft delete now: the row
     * must survive (findIncludingDeleted() still sees it, with deleted_at
     * set), just become invisible everywhere a normal find()/search() is
     * used, same as if it didn't exist.
     */
    public function testDeleteIsSoftAndRecipeStaysRecoverable(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Soft Deleted Soup'])),
            $this->response()
        ))['data'];

        $this->controller->delete(
            $this->request('DELETE', '/api/v1/recipes/' . $created['id'], authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $created['id']]
        );

        $this->assertNull($this->recipes->find($created['id']));

        $stillThere = $this->recipes->findIncludingDeleted($created['id']);
        $this->assertNotNull($stillThere);
        $this->assertSame('Soft Deleted Soup', $stillThere['name']);
        $this->assertNotNull($stillThere['deleted_at']);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1']),
            $this->response()
        ));
        $this->assertNotContains('Soft Deleted Soup', array_column($result['data']['items'], 'name'));

        $this->expectException(NotFoundException::class);
        $this->controller->show($this->request('GET', '/api/v1/recipes/' . $created['id']), $this->response(), ['id' => (string) $created['id']]);
    }

    public function testAdminCanListRestoreAndPermanentlyDeleteSoftDeletedRecipes(): void
    {
        $userId = $this->createUser();
        $adminId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Trash Test Soup'])),
            $this->response()
        ))['data'];
        $this->controller->delete(
            $this->request('DELETE', '/api/v1/recipes/' . $created['id'], authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $created['id']]
        );

        $adminAuth = $this->authPayload($adminId, ['is_admin' => true]);

        $listed = $this->decode($this->controller->adminListDeleted($this->request('GET', '/api/v1/admin/recipes/deleted', authPayload: $adminAuth), $this->response()));
        $this->assertContains('Trash Test Soup', array_column($listed['data'], 'name'));

        $restored = $this->decode($this->controller->adminRestore(
            $this->request('PUT', '/api/v1/admin/recipes/' . $created['id'] . '/restore', authPayload: $adminAuth),
            $this->response(),
            ['id' => (string) $created['id']]
        ));
        $this->assertNull($restored['data']['deleted_at']);
        $this->assertNotNull($this->recipes->find($created['id']));

        $this->controller->delete(
            $this->request('DELETE', '/api/v1/recipes/' . $created['id'], authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $created['id']]
        );
        $permanentResponse = $this->controller->adminPermanentlyDelete(
            $this->request('DELETE', '/api/v1/admin/recipes/' . $created['id'] . '/permanent', authPayload: $adminAuth),
            $this->response(),
            ['id' => (string) $created['id']]
        );
        $this->assertSame(204, $permanentResponse->getStatusCode());
        $this->assertNull($this->recipes->findIncludingDeleted($created['id']));

        $listedAfter = $this->decode($this->controller->adminListDeleted($this->request('GET', '/api/v1/admin/recipes/deleted', authPayload: $adminAuth), $this->response()));
        $this->assertNotContains('Trash Test Soup', array_column($listedAfter['data'], 'name'));
    }

    public function testAdminTrashEndpointsRejectNonAdmins(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $this->expectException(ForbiddenException::class);
        $this->controller->adminListDeleted($this->request('GET', '/api/v1/admin/recipes/deleted', authPayload: $auth), $this->response());
    }

    public function testAdminRestoreRejectsNonAdmins(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $this->expectException(ForbiddenException::class);
        $this->controller->adminRestore($this->request('PUT', '/api/v1/admin/recipes/1/restore', authPayload: $auth), $this->response(), ['id' => '1']);
    }

    public function testAdminPermanentlyDeleteRejectsNonAdmins(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $this->expectException(ForbiddenException::class);
        $this->controller->adminPermanentlyDelete($this->request('DELETE', '/api/v1/admin/recipes/1/permanent', authPayload: $auth), $this->response(), ['id' => '1']);
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
     * todo.md "Rating Filter" - a recipe averaging below the threshold, and
     * a recipe with no ratings at all, must both be excluded; one at or
     * above the threshold must be included.
     */
    public function testSearchFiltersByMinimumAverageRating(): void
    {
        $userId = $this->createUser();
        $highlyRated = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Highly Rated'])),
            $this->response()
        ))['data'];
        $poorlyRated = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Poorly Rated'])),
            $this->response()
        ))['data'];
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Unrated'])),
            $this->response()
        );

        $rater = $this->createUser();
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $highlyRated['id'] . '/rating', authPayload: $this->authPayload($rater), jsonBody: ['rating' => 5]), $this->response(), ['id' => (string) $highlyRated['id']]);
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $poorlyRated['id'] . '/rating', authPayload: $this->authPayload($rater), jsonBody: ['rating' => 2]), $this->response(), ['id' => (string) $poorlyRated['id']]);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', queryParams: ['min_rating' => '4']),
            $this->response()
        ));

        $names = array_column($result['data']['items'], 'name');
        $this->assertContains('Highly Rated', $names);
        $this->assertNotContains('Poorly Rated', $names);
        $this->assertNotContains('Unrated', $names);
    }

    /**
     * An out-of-range min_rating (not 1-5) is silently ignored rather than
     * rejected - same tolerance as this endpoint's other query filters.
     */
    public function testSearchIgnoresAnInvalidMinRating(): void
    {
        $userId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Unrated Recipe'])),
            $this->response()
        );

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', queryParams: ['min_rating' => '9']),
            $this->response()
        ));

        $this->assertContains('Unrated Recipe', array_column($result['data']['items'], 'name'));
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

    /**
     * No sort params at all must still behave exactly like before this
     * feature existed - newest first.
     */
    public function testSearchDefaultsToNewestFirst(): void
    {
        $userId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Older Sort Recipe'])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Newer Sort Recipe'])),
            $this->response()
        );

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1']),
            $this->response()
        ));

        $this->assertSame(['Newer Sort Recipe', 'Older Sort Recipe'], array_column($result['data']['items'], 'name'));
    }

    public function testSearchSortsByName(): void
    {
        $userId = $this->createUser();
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Banana Bread'])),
            $this->response()
        );
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Apple Pie'])),
            $this->response()
        );

        $ascending = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => 'name', 'direction' => 'asc']),
            $this->response()
        ));
        $this->assertSame(['Apple Pie', 'Banana Bread'], array_column($ascending['data']['items'], 'name'));

        $descending = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => 'name', 'direction' => 'desc']),
            $this->response()
        ));
        $this->assertSame(['Banana Bread', 'Apple Pie'], array_column($descending['data']['items'], 'name'));
    }

    /**
     * A recipe created *before* the other one, but with a later updated_at,
     * must sort first under updated_at descending - proves updated_at
     * sorting is actually distinct from the created_at default, not just
     * coincidentally matching it. updated_at is set directly via SQL rather
     * than relying on update() + real wall-clock time between the two
     * creates/the update, which - given `updated_at DATETIME` is only
     * second-precision (database/migrations/002_create_recipe_schema.sql) -
     * could otherwise tie within the same second on a fast test run and
     * make this test flaky.
     */
    public function testSearchSortsByUpdatedAt(): void
    {
        $userId = $this->createUser();
        $first = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Updated First'])),
            $this->response()
        ))['data'];
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Created Second'])),
            $this->response()
        );

        $this->pdo->prepare('UPDATE recipe SET updated_at = ? WHERE id = ?')->execute(['2099-01-01 00:00:00', $first['id']]);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => 'updated_at', 'direction' => 'desc']),
            $this->response()
        ));
        $this->assertSame(['Updated First', 'Created Second'], array_column($result['data']['items'], 'name'));
    }

    /**
     * Unrated recipes must sort to the end regardless of direction - MySQL's
     * own default NULL ordering would otherwise put them *first* on an
     * ascending sort.
     */
    public function testSearchSortsByRatingWithUnratedAlwaysLast(): void
    {
        $userId = $this->createUser();
        $topRated = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Top Rated'])),
            $this->response()
        ))['data'];
        $poorlyRated = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Poorly Rated'])),
            $this->response()
        ))['data'];
        $this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['name' => 'Unrated'])),
            $this->response()
        );

        $rater = $this->createUser();
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $topRated['id'] . '/rating', authPayload: $this->authPayload($rater), jsonBody: ['rating' => 5]), $this->response(), ['id' => (string) $topRated['id']]);
        $this->controller->rate($this->request('PUT', '/api/v1/recipes/' . $poorlyRated['id'] . '/rating', authPayload: $this->authPayload($rater), jsonBody: ['rating' => 2]), $this->response(), ['id' => (string) $poorlyRated['id']]);

        $descending = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => 'rating', 'direction' => 'desc']),
            $this->response()
        ));
        $this->assertSame(['Top Rated', 'Poorly Rated', 'Unrated'], array_column($descending['data']['items'], 'name'));

        $ascending = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => 'rating', 'direction' => 'asc']),
            $this->response()
        ));
        $this->assertSame(['Poorly Rated', 'Top Rated', 'Unrated'], array_column($ascending['data']['items'], 'name'));
    }

    /**
     * Total time = prep + rest + cook; recipes without any time sort last
     * regardless of direction. Cook time sorts on cook_time_minutes alone.
     */
    public function testSearchSortsByTotalAndCookTimeWithMissingAlwaysLast(): void
    {
        $userId = $this->createUser();
        foreach ([
            ['Quick', 5, null, 10],
            ['Slow', 10, 120, 60],
            ['NoTime', null, null, null],
        ] as [$name, $prep, $rest, $cook]) {
            $this->controller->create(
                $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload([
                    'name' => $name, 'prep_time_minutes' => $prep, 'rest_time_minutes' => $rest, 'cook_time_minutes' => $cook,
                ])),
                $this->response()
            );
        }

        $names = fn (string $sort, string $dir) => array_column($this->decode($this->controller->index(
            $this->request('GET', '/api/v1/recipes', authPayload: $this->authPayload($userId), queryParams: ['mine' => '1', 'sort' => $sort, 'direction' => $dir]),
            $this->response()
        ))['data']['items'], 'name');

        $this->assertSame(['Quick', 'Slow', 'NoTime'], $names('total_time', 'asc'));
        $this->assertSame(['Slow', 'Quick', 'NoTime'], $names('total_time', 'desc'));
        $this->assertSame(['Quick', 'Slow', 'NoTime'], $names('cook_time', 'asc'));
        $this->assertSame(['Slow', 'Quick', 'NoTime'], $names('cook_time', 'desc'));
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

    public function testOcrUsesGeminiWhenConfiguredInsteadOfVision(): void
    {
        $userId = $this->createUser();
        $controller = new RecipeController(
            $this->recipes,
            new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'),
            visionOcr: new VisionOcrService('fake-key', function () {
                $this->fail('Vision must not be called when Gemini is configured.');
            }),
            gemini: new GeminiRecipeExtractor('fake-key', 'm', fn () => ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'name' => 'Pfannkuchen',
                'ingredients' => [['name' => 'Milch', 'amount' => 250, 'unit' => 'ml', 'note' => null, 'is_heading' => false]],
                'steps' => [['instruction' => 'Braten.', 'is_heading' => false]],
                'notes' => null,
                'raw_text' => 'Pfannkuchen',
            ])]]]]]])]),
        );
        $request = $this->request('POST', '/api/v1/recipes/ocr', authPayload: $this->authPayload($userId))
            ->withUploadedFiles(['images' => [$this->fakeUploadedImage()]]);

        $result = $this->decode($controller->ocr($request, $this->response()));

        $this->assertSame('Pfannkuchen', $result['data']['name']);
        $this->assertEquals(250, $result['data']['ingredients'][0]['amount']);
        $this->assertSame('Braten.', $result['data']['steps'][0]['instruction']);
        $this->assertSame('Pfannkuchen', $result['data']['raw_text']);
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

    /**
     * todo.md "Pagination in admin/tags is broken" - public/js/config.js's
     * ADMIN_LIST_PAGE_SIZES (5/10/50/100) isn't a subset of the recipe
     * list's own admin-configurable page sizes (10/20/100 by default) -
     * per_page=50 specifically used to silently fail search()'s
     * in_array() whitelist check and fall back to the recipe list's
     * default every time, making admin/tags' rows-per-page selector look
     * broken.
     */
    public function testAdminIndexHonorsAdminSpecificPageSizeNotInRecipeListWhitelist(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        for ($i = 1; $i <= 12; $i++) {
            $this->controller->create(
                $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: $this->payload(['name' => 'Admin Page Size Test ' . $i])),
                $this->response()
            );
        }

        $result = $this->decode($this->controller->adminIndex(
            $this->request('GET', '/api/v1/admin/recipes', authPayload: $this->authPayload($adminId, ['is_admin' => true]), queryParams: ['per_page' => '50']),
            $this->response()
        ));

        $this->assertSame(50, $result['data']['per_page']);
        $this->assertGreaterThanOrEqual(12, count($result['data']['items']));
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

    /**
     * todo.md "Import aus Kochbuch von Chefkoch.de" dedup check.
     */
    public function testExistingSourceUrlsReturnsOnlyTheMatchingSubset(): void
    {
        $userId = $this->createUser();
        $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['source_url' => 'https://www.chefkoch.de/rezepte/1/a.html'])),
            $this->response()
        ));
        $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload(['source_url' => 'https://www.chefkoch.de/rezepte/2/b.html'])),
            $this->response()
        ));

        $existing = $this->recipes->existingSourceUrls([
            'https://www.chefkoch.de/rezepte/1/a.html',
            'https://www.chefkoch.de/rezepte/2/b.html',
            'https://www.chefkoch.de/rezepte/3/c.html',
        ]);

        sort($existing);
        $this->assertSame([
            'https://www.chefkoch.de/rezepte/1/a.html',
            'https://www.chefkoch.de/rezepte/2/b.html',
        ], $existing);
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

    /**
     * todo.md "Recipe images" - "If only one image is available, it is
     * automatically selected as the default image."
     */
    public function testFirstUploadedImageAutomaticallyBecomesTheDefault(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $imageId = $this->recipes->addImage($created['data']['id'], 'first.png');

        $show = $this->decode($this->controller->show(
            $this->request('GET', '/api/v1/recipes/' . $created['data']['id']),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));
        $this->assertSame($imageId, $show['data']['primary_image_id']);
    }

    /**
     * todo.md "Recipe images" - once several images exist, the owner can
     * explicitly pick which one is the default (RecipeController::
     * setPrimaryImage(), exposed in recipe-detail.js's image gallery).
     */
    public function testSetPrimaryImageChangesTheDefaultImage(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));
        $this->recipes->addImage($created['data']['id'], 'first.png');
        $secondImageId = $this->recipes->addImage($created['data']['id'], 'second.png');

        $result = $this->decode($this->controller->setPrimaryImage(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/primary-image', authPayload: $this->authPayload($userId), jsonBody: ['image_id' => $secondImageId]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        ));

        $this->assertSame($secondImageId, $result['data']['primary_image_id']);
    }

    public function testSetPrimaryImageRequiresOwnership(): void
    {
        $ownerId = $this->createUser();
        $otherId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($ownerId), jsonBody: $this->payload()),
            $this->response()
        ));
        $imageId = $this->recipes->addImage($created['data']['id'], 'first.png');

        $this->expectException(ForbiddenException::class);
        $this->controller->setPrimaryImage(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/primary-image', authPayload: $this->authPayload($otherId), jsonBody: ['image_id' => $imageId]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    public function testSetPrimaryImageRejectsAnImageThatDoesNotBelongToTheRecipe(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));

        $this->expectException(NotFoundException::class);
        $this->controller->setPrimaryImage(
            $this->request('PUT', '/api/v1/recipes/' . $created['data']['id'] . '/primary-image', authPayload: $this->authPayload($userId), jsonBody: ['image_id' => 999999]),
            $this->response(),
            ['id' => (string) $created['data']['id']]
        );
    }

    /**
     * Reported bug: deleting the current default image left another
     * uploaded image orphaned (the recipe fell back to showing the
     * placeholder image instead) rather than promoting it. FK ON DELETE SET
     * NULL only clears primary_image_id, it doesn't pick a replacement -
     * see RecipeRepository::removeImage()'s own fix/doc-comment.
     */
    public function testDeletingTheDefaultImagePromotesTheRemainingOneAsTheNewDefault(): void
    {
        $userId = $this->createUser();
        $created = $this->decode($this->controller->create(
            $this->request('POST', '/api/v1/recipes', authPayload: $this->authPayload($userId), jsonBody: $this->payload()),
            $this->response()
        ));
        $firstImageId = $this->recipes->addImage($created['data']['id'], 'first.png');
        $secondImageId = $this->recipes->addImage($created['data']['id'], 'second.png');

        $result = $this->decode($this->controller->deleteImage(
            $this->request('DELETE', '/api/v1/recipes/' . $created['data']['id'] . '/images/' . $firstImageId, authPayload: $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $created['data']['id'], 'imageId' => (string) $firstImageId]
        ));

        $this->assertSame($secondImageId, $result['data']['primary_image_id']);
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
