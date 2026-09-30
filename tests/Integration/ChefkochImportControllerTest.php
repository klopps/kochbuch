<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Domain\Recipe\RecipeRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\UnauthorizedException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\ChefkochImportController;
use Kochbuch\Service\ChefkochImportService;
use Kochbuch\Service\RecipeImageService;

/**
 * Calls ChefkochImportController methods directly (see ControllerTestCase).
 * ChefkochImportService is always built with a fake sender here - this
 * suite must never make a real HTTP request to chefkoch.de, same
 * "injectable transport" reasoning as BringServiceTest-equivalent coverage
 * elsewhere in this project.
 */
final class ChefkochImportControllerTest extends ControllerTestCase
{
    private RecipeRepository $recipes;

    protected function setUp(): void
    {
        parent::setUp();

        $placeholderImages = new PlaceholderImageRepository($this->pdo);
        $this->recipes = new RecipeRepository($this->pdo, $placeholderImages);
    }

    private function controller(callable $sender): ChefkochImportController
    {
        return new ChefkochImportController(
            $this->recipes,
            new RecipeImageService(sys_get_temp_dir() . '/kochbuch-test-images'),
            new ChefkochImportService($sender)
        );
    }

    /**
     * A minimal but realistic fixture, modeled on a real chefkoch.de recipe
     * page's JSON-LD verified live while planning this feature: a @graph
     * array with the Recipe node plus a separate ImageObject node the
     * recipe's own "image" field only references by @id - exactly the
     * shape ChefkochImportService::resolveGraphReferences() exists to
     * flatten before handing off to SchemaOrgRecipeParser.
     */
    private function fakeRecipePageHtml(string $name = 'Testkeks'): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Recipe',
                    'name' => $name,
                    'recipeYield' => ['4', '4 Portionen'],
                    'prepTime' => 'PT10M',
                    'cookTime' => 'PT20M',
                    'recipeIngredient' => ['200 g Mehl', '2 Ei(er)'],
                    'recipeInstructions' => [
                        ['@type' => 'HowToStep', 'text' => 'Alles vermischen.'],
                        ['@type' => 'HowToStep', 'text' => 'Backen.'],
                    ],
                    'image' => ['@id' => 'https://www.chefkoch.de/rezepte/1/testkeks.html#primaryimage'],
                    'suitableForDiet' => 'https://schema.org/VegetarianDiet',
                ],
                [
                    '@type' => 'ImageObject',
                    '@id' => 'https://www.chefkoch.de/rezepte/1/testkeks.html#primaryimage',
                    'url' => 'https://img.chefkoch-cdn.de/testkeks.jpg',
                ],
            ],
        ]);

        return '<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>';
    }

    /**
     * A real private-recipe API response, live-verified 2026-10-01 (see
     * ChefkochImportService's doc-comment) - field names, the
     * blank-line-paragraph instructions format, and the "0 means not set"
     * convention for the numeric fields are all taken from that real
     * response, just with personal details swapped out.
     */
    private function fakePrivateRecipeJson(): array
    {
        return [
            'id' => 'user-1',
            'title' => 'Testkeks',
            'subtitle' => '',
            'difficulty' => 2,
            'hasImage' => true,
            'preparationTime' => 10,
            'cookingTime' => 20,
            'restingTime' => 0,
            'servings' => 4,
            'kCalories' => 0,
            'instructions' => "Alles vermischen.\n\nBacken.",
            'ingredientsText' => "200 g Mehl\n2 Ei(er)",
            'source' => ['name' => null, 'url' => null],
            'siteUrl' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-1',
            'previewImageUrlTemplate' => 'https://img.chefkoch-cdn.de/assets/v1/img/<format>/s.png',
        ];
    }

    /**
     * A real private-recipe API response using the newer *structured*
     * shape - live-verified 2026-10-01 (see class doc-comment), trimmed to
     * two ingredient groups/two steps but otherwise faithful to the real
     * field names and nesting (recipeIngredientGroups[].ingredients[].food.
     * nameSingular/unit.nameSingular/amount/properties,
     * recipeInstructions[].steps[].text). ingredientsText/instructions are
     * also populated here (Chefkoch keeps them as a freetext mirror), but
     * mapIngredientGroups()/mapStructuredInstructions() must prefer this
     * structured data since it carries real units/notes the freetext
     * regex-parser can't reliably separate out on its own.
     */
    private function fakeStructuredPrivateRecipeJson(): array
    {
        return [
            'id' => 'user-2',
            'title' => 'Halloumi-Burger',
            'subtitle' => '',
            'difficulty' => 2,
            'hasImage' => false,
            'preparationTime' => 20,
            'cookingTime' => 10,
            'restingTime' => 0,
            'servings' => 4,
            'kCalories' => 0,
            'instructions' => "Abschnitt 1:\n\nAvocado zerdrücken.\n\nBurger belegen.",
            'ingredientsText' => "Für das Avocadomus:\n1 Avocado\n\nAußerdem:\n300 g Halloumi",
            'recipeIngredientGroups' => [
                [
                    'header' => 'Für das Avocadomus',
                    'position' => 0,
                    'ingredients' => [
                        [
                            'unit' => null,
                            'food' => ['id' => '1006', 'nameSingular' => 'Avocado', 'namePlural' => 'Avocados'],
                            'amount' => 1.0,
                            'properties' => [],
                            'position' => 0,
                        ],
                        [
                            'unit' => ['id' => 13, 'nameSingular' => 'EL', 'namePlural' => 'EL'],
                            'food' => ['id' => '15264', 'nameSingular' => 'Koriander', 'namePlural' => 'Koriander'],
                            'amount' => 2.0,
                            'properties' => [],
                            'position' => 1,
                        ],
                    ],
                ],
                [
                    'header' => 'Außerdem',
                    'position' => 1,
                    'ingredients' => [
                        [
                            'unit' => null,
                            'food' => ['id' => '67', 'nameSingular' => 'Chilischote', 'namePlural' => 'Chilischoten'],
                            'amount' => 1.0,
                            'properties' => ['rote', 'frisch'],
                            'position' => 0,
                        ],
                        [
                            'unit' => ['id' => 3, 'nameSingular' => 'g', 'namePlural' => 'g'],
                            'food' => ['id' => '14302', 'nameSingular' => 'Halloumi', 'namePlural' => 'Halloumi'],
                            'amount' => 300.0,
                            'properties' => [],
                            'position' => 1,
                        ],
                    ],
                ],
            ],
            'recipeInstructions' => [
                [
                    'header' => 'Abschnitt 1',
                    'position' => 0,
                    'steps' => [
                        ['position' => 0, 'text' => 'Avocado zerdrücken.'],
                        ['position' => 1, 'text' => 'Burger belegen.'],
                    ],
                ],
            ],
            'source' => ['name' => null, 'url' => null],
            'siteUrl' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-2',
            'previewImageUrlTemplate' => 'https://img.chefkoch-cdn.de/assets/v1/img/placeholders/4x3/h.png',
        ];
    }

    private function fakeSender(): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body) {
            // The real, paginated list endpoint (live-verified 2026-10-01) -
            // matched before the /private-recipes/{id} detail branches below
            // since it's a completely different path, not a prefix of them.
            if (preg_match('#/cookbooks/user-[^/]+/recipes\?#', $url) === 1) {
                // A single page smaller than LIST_PAGE_SIZE is enough to
                // make listCookbook() stop after one request. Each entry is
                // wrapped one level deeper under "recipe" (plus sibling
                // note/createdBy/... fields Kochbuch doesn't use) - the real,
                // live-verified shape, not the recipe fields directly.
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['count' => 1, 'results' => [
                    ['recipe' => ['id' => 'user-1', 'title' => 'Testkeks', 'siteUrl' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-1'], 'note' => ''],
                ]])];
            }
            if (str_contains($url, '/cookbooks/user-owner123/private-recipes/user-1')) {
                return ['status' => 200, 'headers' => [], 'body' => json_encode($this->fakePrivateRecipeJson())];
            }
            if (str_contains($url, '/cookbooks/user-owner123/private-recipes/user-2')) {
                return ['status' => 200, 'headers' => [], 'body' => json_encode($this->fakeStructuredPrivateRecipeJson())];
            }
            if (str_contains($url, '/rezepte/999/missing.html')) {
                // No matching private-recipes branch either for id "999",
                // so the fetchRecipe() fallback also 404s via the catch-all
                // below - both paths fail, exactly like a genuinely unknown
                // chefkoch_id would.
                return ['status' => 404, 'headers' => [], 'body' => 'not found'];
            }
            if (str_contains($url, 'chefkoch.de/rezepte/')) {
                return ['status' => 200, 'headers' => [], 'body' => $this->fakeRecipePageHtml()];
            }
            if (str_contains($url, 'img.chefkoch-cdn.de')) {
                return ['status' => 200, 'headers' => [], 'body' => $this->tinyPngBytes()];
            }

            return ['status' => 404, 'headers' => [], 'body' => ''];
        };
    }

    public function testListRequiresAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller($this->fakeSender())->list(
            $this->request('POST', '/api/v1/admin/chefkoch-import/list', authPayload: $this->authPayload($userId), jsonBody: ['token' => 'x']),
            $this->response()
        );
    }

    public function testListRequiresAuthentication(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller($this->fakeSender())->list(
            $this->request('POST', '/api/v1/admin/chefkoch-import/list', jsonBody: ['token' => 'x']),
            $this->response()
        );
    }

    public function testListRequiresAToken(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        try {
            $this->controller($this->fakeSender())->list(
                $this->request('POST', '/api/v1/admin/chefkoch-import/list', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: []),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('chefkoch.token_required', $e->getErrorCode());
        }
    }

    /**
     * The owner id is derived from the token's own "{ownerId}-{rest}"
     * structure (see ChefkochImportController::resolveOwnerId()) - a token
     * with no dash at all can't be split into an owner id.
     */
    public function testListRequiresATokenWithADerivableOwnerId(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        try {
            $this->controller($this->fakeSender())->list(
                $this->request('POST', '/api/v1/admin/chefkoch-import/list', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: ['token' => 'nodashhere']),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('chefkoch.owner_id_required', $e->getErrorCode());
        }
    }

    public function testListFlagsAlreadyImportedRecipesBySourceUrl(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);
        // Pre-seed a recipe whose source_url matches what the fake sender's
        // private-recipes listing will report for chefkoch id "user-1".
        $this->recipes->create($adminId, [
            'name' => 'Existing', 'description' => null, 'servings' => 4, 'difficulty' => 'normal',
            'prep_time_minutes' => null, 'rest_time_minutes' => null, 'cook_time_minutes' => null,
            'notes' => null, 'calories' => null, 'allergen_info' => null,
            'is_vegan' => false, 'is_vegetarian' => false, 'is_pescetarian' => false,
            'source' => 'Chefkoch.de', 'source_url' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-1',
            'visibility' => 'private', 'ingredients' => [], 'steps' => [], 'tags' => [],
        ]);

        $result = $this->decode($this->controller($this->fakeSender())->list(
            $this->request('POST', '/api/v1/admin/chefkoch-import/list', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: ['token' => 'owner123-x']),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['data']['recipes']);
        $this->assertTrue($result['data']['recipes'][0]['already_imported']);
        $this->assertSame('https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-1', $result['data']['recipes'][0]['source_url']);
    }

    /**
     * listCookbook() has to page through LIST_PAGE_SIZE-sized chunks itself
     * (the real endpoint is paginated, live-verified 2026-10-01) - a full
     * first page must trigger a second request at the next offset, and a
     * shorter final page must stop the loop.
     */
    public function testListPaginatesThroughMultiplePages(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $sender = function (string $method, string $url, array $headers, ?string $body) {
            if (preg_match('#/cookbooks/user-pager/recipes\?offset=(\d+)&limit=12#', $url, $m) === 1) {
                $offset = (int) $m[1];
                if ($offset === 0) {
                    $items = array_map(
                        static fn (int $i) => ['recipe' => ['id' => 'user-p' . $i, 'title' => 'Recipe ' . $i, 'siteUrl' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-p' . $i]],
                        range(1, 12)
                    );

                    return ['status' => 200, 'headers' => [], 'body' => json_encode(['count' => 13, 'results' => $items])];
                }
                if ($offset === 12) {
                    return ['status' => 200, 'headers' => [], 'body' => json_encode(['count' => 13, 'results' => [
                        ['recipe' => ['id' => 'user-p13', 'title' => 'Recipe 13', 'siteUrl' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-p13']],
                    ]])];
                }
            }

            return ['status' => 404, 'headers' => [], 'body' => ''];
        };

        $result = $this->decode($this->controller($sender)->list(
            $this->request('POST', '/api/v1/admin/chefkoch-import/list', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: ['token' => 'pager-x']),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertCount(13, $result['data']['recipes']);
        $this->assertSame('user-p13', $result['data']['recipes'][12]['chefkoch_id']);
    }

    public function testImportCreatesARecipeFromTheFetchedPage(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $result = $this->decode($this->controller($this->fakeSender())->import(
            $this->request('POST', '/api/v1/admin/chefkoch-import/import', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'token' => 'owner123-x',
                'recipes' => [
                    ['chefkoch_id' => '1', 'source_url' => 'https://www.chefkoch.de/rezepte/1/testkeks.html', 'name' => 'Testkeks', 'collection' => 'Kekse'],
                ],
            ]),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('created', $result['data']['results'][0]['status']);
        $recipeId = $result['data']['results'][0]['id'];

        $created = $this->recipes->find($recipeId);
        $this->assertSame('Testkeks', $created['name']);
        $this->assertSame('https://www.chefkoch.de/rezepte/1/testkeks.html', $created['source_url']);
        $this->assertTrue($created['is_vegetarian']);
        $this->assertContains('Kekse', $created['tags']);
        // Every import gets this tag automatically, regardless of collection.
        $this->assertContains('chefkoch-import', $created['tags']);
        $this->assertCount(2, $created['ingredients']);
        $this->assertSame('Mehl', $created['ingredients'][0]['name']);
        $this->assertCount(2, $created['steps']);
        // The image was fetched through the same authenticated sender and attached.
        $this->assertCount(1, $created['images']);
    }

    /**
     * The primary real-world path: a private "Mein Kochbuch" recipe has no
     * public page at all, so fetchRecipe() falls through to the
     * authenticated private-recipes API - using the exact real response
     * shape confirmed live (see fakePrivateRecipeJson()'s doc-comment).
     */
    public function testImportCreatesARecipeFromThePrivateRecipeApi(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $result = $this->decode($this->controller($this->fakeSender())->import(
            $this->request('POST', '/api/v1/admin/chefkoch-import/import', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'token' => 'owner123-x',
                'recipes' => [
                    ['chefkoch_id' => 'user-1', 'source_url' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-1', 'name' => 'Testkeks'],
                ],
            ]),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('created', $result['data']['results'][0]['status']);
        $created = $this->recipes->find($result['data']['results'][0]['id']);

        $this->assertSame('Testkeks', $created['name']);
        $this->assertSame(4, $created['servings']);
        $this->assertSame(10, $created['prep_time_minutes']);
        $this->assertSame(20, $created['cook_time_minutes']);
        // restingTime/kCalories were both 0 in the fixture - "not set", not a real zero.
        $this->assertNull($created['rest_time_minutes']);
        $this->assertNull($created['calories']);
        $this->assertSame('normal', $created['difficulty']);
        // No collection was passed for this item, so "chefkoch-import" must
        // be the only tag - it's not conditional on a collection being set.
        $this->assertSame(['chefkoch-import'], $created['tags']);
        $this->assertCount(2, $created['ingredients']);
        $this->assertSame('Mehl', $created['ingredients'][0]['name']);
        // instructions was one blob with a blank line between the two steps.
        $this->assertCount(2, $created['steps']);
        $this->assertSame('Alles vermischen.', $created['steps'][0]['instruction']);
        $this->assertSame('Backen.', $created['steps'][1]['instruction']);
        $this->assertCount(1, $created['images']);
    }

    /**
     * The newer, structured private-recipe shape (recipeIngredientGroups/
     * recipeInstructions populated) must be preferred over the freetext
     * ingredientsText/instructions fields that are also present alongside
     * it - real units/notes, not regex guesses.
     */
    public function testImportPrefersStructuredIngredientsAndInstructionsWhenPresent(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $result = $this->decode($this->controller($this->fakeSender())->import(
            $this->request('POST', '/api/v1/admin/chefkoch-import/import', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'token' => 'owner123-x',
                'recipes' => [
                    ['chefkoch_id' => 'user-2', 'source_url' => 'https://www.chefkoch.de/mein-kochbuch/privatrezepte/user-2', 'name' => 'Halloumi-Burger'],
                ],
            ]),
            $this->response()
        ));

        $this->assertSame('created', $result['data']['results'][0]['status']);
        $created = $this->recipes->find($result['data']['results'][0]['id']);

        // Two groups, each with its own heading row, 2 ingredients each = 6 rows.
        $this->assertCount(6, $created['ingredients']);
        $this->assertTrue($created['ingredients'][0]['is_heading']);
        $this->assertSame('Für das Avocadomus', $created['ingredients'][0]['name']);
        $this->assertSame('Avocado', $created['ingredients'][1]['name']);
        $this->assertEquals(1.0, $created['ingredients'][1]['amount']);
        $this->assertNull($created['ingredients'][1]['unit']);
        $this->assertSame('Koriander', $created['ingredients'][2]['name']);
        $this->assertSame('EL', $created['ingredients'][2]['unit']);
        $this->assertTrue($created['ingredients'][3]['is_heading']);
        $this->assertSame('Außerdem', $created['ingredients'][3]['name']);
        // properties ["rote", "frisch"] became the note - something the
        // freetext parser can never produce (its note is always null).
        $this->assertSame('Chilischote', $created['ingredients'][4]['name']);
        $this->assertSame('rote, frisch', $created['ingredients'][4]['note']);
        $this->assertSame('Halloumi', $created['ingredients'][5]['name']);
        $this->assertEquals(300.0, $created['ingredients'][5]['amount']);
        $this->assertSame('g', $created['ingredients'][5]['unit']);

        // One section heading + two steps.
        $this->assertCount(3, $created['steps']);
        $this->assertTrue($created['steps'][0]['is_heading']);
        $this->assertSame('Abschnitt 1', $created['steps'][0]['instruction']);
        $this->assertSame('Avocado zerdrücken.', $created['steps'][1]['instruction']);
        $this->assertSame('Burger belegen.', $created['steps'][2]['instruction']);
    }

    public function testImportReportsAPerItemErrorWithoutAbortingTheBatch(): void
    {
        $adminId = $this->createUser(['is_admin' => 1]);

        $result = $this->decode($this->controller($this->fakeSender())->import(
            $this->request('POST', '/api/v1/admin/chefkoch-import/import', authPayload: $this->authPayload($adminId, ['is_admin' => true]), jsonBody: [
                'token' => 'owner123-x',
                'recipes' => [
                    ['chefkoch_id' => '999', 'source_url' => 'https://www.chefkoch.de/rezepte/999/missing.html', 'name' => 'Missing'],
                    ['chefkoch_id' => '1', 'source_url' => 'https://www.chefkoch.de/rezepte/1/testkeks.html', 'name' => 'Testkeks'],
                ],
            ]),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertCount(2, $result['data']['results']);
        $this->assertSame('error', $result['data']['results'][0]['status']);
        $this->assertSame('created', $result['data']['results'][1]['status']);
    }

    public function testImportRequiresAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller($this->fakeSender())->import(
            $this->request('POST', '/api/v1/admin/chefkoch-import/import', authPayload: $this->authPayload($userId), jsonBody: ['token' => 'x', 'recipes' => []]),
            $this->response()
        );
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
}
