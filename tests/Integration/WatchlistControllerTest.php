<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Domain\Watchlist\WatchlistRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\NotFoundException;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Http\Controllers\WatchlistController;

/**
 * todo.md "Watchlist": each user's own ordered "Merkliste".
 */
final class WatchlistControllerTest extends ControllerTestCase
{
    private RecipeRepository $recipes;
    private WatchlistController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipes = new RecipeRepository($this->pdo, new PlaceholderImageRepository($this->pdo));
        $this->controller = new WatchlistController(new WatchlistRepository($this->pdo), $this->recipes);
    }

    private function createRecipe(int $userId, string $name, string $visibility = 'public'): int
    {
        return $this->recipes->create($userId, [
            'name' => $name, 'description' => null, 'servings' => 4, 'difficulty' => 'normal',
            'prep_time_minutes' => null, 'rest_time_minutes' => null, 'cook_time_minutes' => null, 'calories' => null,
            'allergen_info' => null, 'is_vegan' => false, 'is_vegetarian' => false, 'is_pescetarian' => false,
            'source' => null, 'source_url' => null, 'visibility' => $visibility, 'notes' => null,
            'ingredients' => [], 'steps' => [], 'tags' => [],
        ]);
    }

    private function add(int $userId, int $recipeId): array
    {
        return $this->decode($this->controller->add(
            $this->request('POST', '/api/v1/watchlist', authPayload: $this->authPayload($userId), jsonBody: ['recipe_id' => $recipeId]),
            $this->response()
        ));
    }

    private function names(int $userId): array
    {
        $result = $this->decode($this->controller->index($this->request('GET', '/api/v1/watchlist', authPayload: $this->authPayload($userId)), $this->response()));

        return array_column($result['data'], 'name');
    }

    public function testRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->index($this->request('GET', '/api/v1/watchlist'), $this->response());
    }

    public function testAddsToTheEndOncePerRecipeAndKeepsListsPerUser(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $a = $this->createRecipe($other, 'Apfelkuchen');
        $b = $this->createRecipe($other, 'Bohnensuppe');

        $this->add($user, $b);
        $this->add($user, $a);
        $result = $this->add($user, $b); // already on the list - unchanged

        $this->assertSame(201, $result['status']);
        $this->assertSame([$b, $a], $result['data']['recipe_ids']);
        $this->assertSame(['Bohnensuppe', 'Apfelkuchen'], $this->names($user));
        $this->assertSame([], $this->names($other));
    }

    public function testReordersAndRemoves(): void
    {
        $user = $this->createUser();
        $a = $this->createRecipe($user, 'A');
        $b = $this->createRecipe($user, 'B');
        $c = $this->createRecipe($user, 'C');
        foreach ([$a, $b, $c] as $id) {
            $this->add($user, $id);
        }

        // A stale client list (missing C, plus an id not on the list) loses nothing.
        $this->controller->reorder(
            $this->request('PUT', '/api/v1/watchlist/order', authPayload: $this->authPayload($user), jsonBody: ['recipe_ids' => [$b, 999999, $a]]),
            $this->response()
        );
        $this->assertSame(['B', 'A', 'C'], $this->names($user));

        $this->controller->remove($this->request('DELETE', '/api/v1/watchlist/' . $a, authPayload: $this->authPayload($user)), $this->response(), ['recipeId' => (string) $a]);
        $this->assertSame(['B', 'C'], $this->names($user));
    }

    public function testOnlyVisibleRecipesCanBeAddedAndListed(): void
    {
        $user = $this->createUser();
        $owner = $this->createUser();
        $private = $this->createRecipe($owner, 'Geheim', 'private');
        $internal = $this->createRecipe($owner, 'Intern', 'internal');

        try {
            $this->add($user, $private);
            $this->fail('Expected a ForbiddenException.');
        } catch (ForbiddenException) {
        }
        $this->add($user, $internal);
        $this->assertSame(['Intern'], $this->names($user));

        // Made private by its owner later: drops out of the list.
        $this->pdo->prepare('UPDATE recipe SET visibility = "private" WHERE id = ?')->execute([$internal]);
        $this->assertSame([], $this->names($user));
    }

    public function testUnknownRecipeIsNotFound(): void
    {
        $user = $this->createUser();

        $this->expectException(NotFoundException::class);
        $this->add($user, 999999);
    }
}
