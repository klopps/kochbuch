<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Closure;
use RuntimeException;
use Throwable;
use Kochbuch\Exception\ApiException;

/**
 * Fetches a user's personal Chefkoch.de "Kochbuch" (their saved-recipe
 * collection) and individual recipe detail data (todo.md "Import aus
 * Kochbuch von Chefkoch.de"). Mirrors BringService/VisionOcrService's shape
 * exactly: outbound HTTP is a raw curl wrapper injected as a Closure so
 * tests never hit the real, external chefkoch.de.
 *
 * Endpoint knowledge here comes from three sources of different confidence:
 *  - listCookbook()/fetchPrivateRecipe() target Chefkoch's internal `v2`
 *    JSON API (`x-chefkoch-api-token` header), both live-verified against
 *    real requests/responses while fixing this after the first few
 *    real-world tests (2026-10-01, with the user's help reading their own
 *    browser's DevTools Network tab):
 *      - listing: `GET /v2/cookbooks/user-{ownerId}/recipes?offset=&limit=&
 *        order=0&orderBy=2`, paginated (LIST_PAGE_SIZE items per page,
 *        matching the site's own default) - a single unified list covering
 *        both the admin's own private recipes AND any public recipes
 *        they've bookmarked from other users, not split by collection or by
 *        private/public. An earlier version of this guessed a
 *        "/private-recipes" list endpoint (no such thing) and a
 *        "/collections" endpoint (also wrong) before this was confirmed.
 *        Response shape: `{results: [{recipe: {id, title, siteUrl, ...}},
 *        ...], count, owner}` - see listCookbook()'s own doc-comment for
 *        why the recipe fields are one level deeper than they first appear.
 *      - one recipe's detail: `GET /v2/cookbooks/user-{ownerId}/
 *        private-recipes/{id}` (only reachable for the admin's own private
 *        recipes - see fetchPublicRecipe() below for bookmarked public
 *        ones).
 *    Both need the owner id prefixed with "user-". The owner id itself
 *    doesn't need collecting separately - the x-chefkoch-api-token value is
 *    itself "{ownerId}-{rest}" (also the user's own finding), so
 *    ChefkochImportController derives it by splitting the token on its
 *    first "-" rather than asking for it as its own form field (an earlier
 *    version of this did, unnecessarily). Real response field names
 *    confirmed this way (for the single-recipe detail call): title, subtitle, servings, preparationTime/
 *    cookingTime/restingTime (minutes, 0 means "not set"), kCalories (0 =
 *    not set), ingredientsText (one freetext line per ingredient),
 *    instructions (one blob, paragraphs separated by a blank line - NOT one
 *    step per single newline), difficulty (int), source.name/source.url,
 *    siteUrl (the recipe's own canonical page URL), hasImage +
 *    previewImageUrlTemplate (a real photo only when hasImage is true - the
 *    template is a generic placeholder image otherwise).
 *  - fetchPublicRecipe() was verified live during this feature's planning
 *    session against a real chefkoch.de recipe page: it does carry a
 *    complete schema.org Recipe node inside a `<script type="application/
 *    ld+json">` `@graph` array, in exactly the shape SchemaOrgRecipeParser
 *    already expects - except `image`/`author`, which are `{"@id": "..."}`
 *    references into sibling `@graph` nodes rather than inline values (see
 *    JsonLdRecipeFinder, which resolves them). Only reachable for recipes that actually
 *    have a public page though - a private "Mein Kochbuch" recipe (the
 *    primary case for this feature) never does, so fetchRecipe() falls
 *    through to fetchPrivateRecipe() for those every time.
 *  - There is deliberately no username/password login here. Chefkoch shows
 *    a CAPTCHA before login (the original one-time import never automated
 *    it either - a human signed in manually in a browser and that script
 *    only reused the resulting session/token, see done.md), so an admin
 *    always pastes their own x-chefkoch-api-token instead (copied from
 *    their own logged-in browser, DevTools -> Network -> any
 *    api.chefkoch.de request -> x-chefkoch-api-token request header) -
 *    confirmed working end-to-end, including a real 208-recipe cookbook,
 *    2026-09-30.
 */
final class ChefkochImportService
{
    private const API_BASE = 'https://api.chefkoch.de/v2';

    private readonly Closure $sender;

    /**
     * @param (callable(string $method, string $url, array<string,string> $headers, ?string $body): array{status:int, headers:array<string,string>, body:string})|null $sender
     *        Defaults to a real curl-based request; tests inject a fake
     *        that returns canned responses without any network call.
     */
    public function __construct(?callable $sender = null)
    {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
    }

    // The UI's own default page size when browsing "Mein Kochbuch"
    // (live-verified 2026-10-01) - used here too since a larger value isn't
    // confirmed to be honored by the API; listCookbook() pages through as
    // many requests as needed instead of assuming a bigger one is accepted.
    private const LIST_PAGE_SIZE = 12;

    /**
     * Lists every recipe in the admin's personal "Mein Kochbuch" - both
     * self-authored private recipes (`type: 1`, id "user-...") AND any
     * public recipes they've bookmarked from other users (`type: 0`, a
     * plain numeric id) - confirmed live 2026-10-01: this is a single,
     * unified, *paginated* `/recipes` endpoint, not split by collection or
     * by private/public (two earlier versions of this guessed a
     * "/private-recipes" list endpoint and a "/collections" endpoint,
     * neither of which exist). The real response wraps each entry one level
     * deeper than the single-recipe detail response's own shape -
     * `{results: [{recipe: {id, title, siteUrl, ...}, note, createdBy,
     * ...}, ...], count, owner}` - the recipe's own fields are
     * `item.recipe.*`, not `item.*` directly (an earlier version of this
     * read the wrong level and silently found 0 recipes as a result). This
     * endpoint carries no per-item collection/"Sammlung" membership, unlike
     * the old (now-gone) collections-specific API the original one-time
     * import used - so unlike that import, this one can't auto-tag by
     * collection; `collection` here is always ''.
     *
     * Each item's own `siteUrl` is used as-is for fetchRecipe()'s
     * public-page-first attempt, so a bookmarked public recipe naturally
     * resolves through fetchPublicRecipe() while a private one (its siteUrl
     * points at the "Mein Kochbuch" app route, not a real public recipe
     * page) falls through to fetchPrivateRecipe() - listCookbook() itself
     * doesn't need to know which is which.
     *
     * @return array<int, array{chefkoch_id: string, name: string, source_url: string, collection: string}>
     */
    public function listCookbook(string $ownerId, string $token): array
    {
        $recipes = [];
        $offset = 0;

        while (true) {
            $data = $this->apiGet(
                self::API_BASE . '/cookbooks/' . rawurlencode(self::ownerSegment($ownerId))
                    . '/recipes?offset=' . $offset . '&limit=' . self::LIST_PAGE_SIZE . '&order=0&orderBy=2',
                $token
            );
            $items = $data['results'] ?? $data['items'] ?? $data['recipes'] ?? $data;
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $recipe = is_array($item['recipe'] ?? null) ? $item['recipe'] : $item;
                $id = (string) ($recipe['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $recipes[] = [
                    'chefkoch_id' => $id,
                    'name' => (string) RecipeNameCase::fix((string) ($recipe['title'] ?? '')),
                    'source_url' => (string) ($recipe['siteUrl'] ?? ('https://www.chefkoch.de/mein-kochbuch/privatrezepte/' . $id)),
                    'collection' => '',
                ];
            }

            if (count($items) < self::LIST_PAGE_SIZE) {
                break;
            }
            $offset += self::LIST_PAGE_SIZE;
        }

        return $recipes;
    }

    /**
     * @return array{name: string, description: ?string, servings: ?int, prep_time_minutes: ?int, cook_time_minutes: ?int, rest_time_minutes: ?int, calories: ?int, is_vegan: bool, is_vegetarian: bool, is_pescetarian: bool, source: ?string, source_url: ?string, tags: string[], ingredients: array[], steps: array[], image_url: ?string}
     */
    public function fetchRecipe(string $chefkochId, string $sourceUrl, string $ownerId, string $token, SchemaOrgRecipeParser $parser, IngredientLineParser $ingredientParser): array
    {
        try {
            $mapped = $this->fetchPublicRecipe($sourceUrl, $parser);
            $mapped['source_url'] = $sourceUrl;
        } catch (Throwable) {
            $mapped = $this->fetchPrivateRecipe($chefkochId, $ownerId, $token, $ingredientParser);
        }

        return $mapped;
    }

    private static function ownerSegment(string $ownerId): string
    {
        return str_starts_with($ownerId, 'user-') ? $ownerId : 'user-' . $ownerId;
    }

    /**
     * The verified path: fetch the public recipe page and read its
     * schema.org Recipe JSON-LD via JsonLdRecipeFinder - which also resolves
     * Chefkoch's `@id`-only image/author references and drops the
     * auto-generated SEO `description` every chefkoch.de page carries (see
     * there). Shared with the shared-link import (LinkRecipeReader), so both
     * read a Chefkoch page identically.
     */
    private function fetchPublicRecipe(string $sourceUrl, SchemaOrgRecipeParser $parser): array
    {
        $result = ($this->sender)('GET', $sourceUrl, [], null);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new RuntimeException('Recipe page returned HTTP ' . $result['status']);
        }

        $recipeNode = JsonLdRecipeFinder::find($result['body'], $sourceUrl);
        if ($recipeNode === null) {
            throw new RuntimeException('No Recipe node found in JSON-LD.');
        }

        return $parser->parse($recipeNode);
    }

    /**
     * Own/private recipes aren't publicly viewable, so they never reach
     * fetchPublicRecipe()'s page-fetch successfully - this uses the
     * authenticated `v2` API instead. Field mapping confirmed against two
     * real responses (see class doc-comment): an older "legacy" recipe
     * carries only freetext ingredientsText/instructions
     * (recipeIngredientGroups/recipeInstructions both empty arrays), while a
     * newer recipe has both the freetext fields (kept as a compatibility
     * mirror) AND fully structured recipeIngredientGroups/recipeInstructions
     * - this prefers the structured data when present (real amount/unit/
     * food/properties instead of IngredientLineParser's regex guesses) and
     * falls back to the freetext fields otherwise. instructions/
     * recipeInstructions' text both use a blank line between steps (in the
     * freetext blob) or one step object per array entry (structured) rather
     * than one step per single newline; 0 consistently means "not set" for
     * the numeric time/calorie fields rather than a real zero.
     */
    private function fetchPrivateRecipe(string $chefkochId, string $ownerId, string $token, IngredientLineParser $ingredientParser): array
    {
        $data = $this->apiGet(self::API_BASE . '/cookbooks/' . rawurlencode(self::ownerSegment($ownerId)) . '/private-recipes/' . rawurlencode($chefkochId), $token);

        $ingredients = !empty($data['recipeIngredientGroups'])
            ? $this->mapIngredientGroups($data['recipeIngredientGroups'])
            : $this->mapFreetextIngredients((string) ($data['ingredientsText'] ?? ''), $ingredientParser);

        $steps = !empty($data['recipeInstructions'])
            ? $this->mapStructuredInstructions($data['recipeInstructions'])
            : $this->mapFreetextInstructions((string) ($data['instructions'] ?? ''));

        $imageUrl = null;
        if (!empty($data['hasImage']) && !empty($data['previewImageUrlTemplate'])) {
            $imageUrl = str_replace('<format>', '4x3', (string) $data['previewImageUrlTemplate']);
        }

        // todo.md: a title ending in " von <Benutzername>" (Chefkoch's own
        // credit convention) has that suffix moved into the description as
        // a "Rezept von ..." note instead.
        ['name' => $name, 'description' => $description] = RecipeTitleAuthorSplitter::split(
            (string) ($data['title'] ?? ''),
            ($data['subtitle'] ?? '') !== '' ? (string) $data['subtitle'] : null
        );

        return [
            'name' => (string) RecipeNameCase::fix($name),
            'description' => $description,
            'servings' => !empty($data['servings']) ? (int) $data['servings'] : null,
            'prep_time_minutes' => !empty($data['preparationTime']) ? (int) $data['preparationTime'] : null,
            'cook_time_minutes' => !empty($data['cookingTime']) ? (int) $data['cookingTime'] : null,
            'rest_time_minutes' => !empty($data['restingTime']) ? (int) $data['restingTime'] : null,
            'calories' => !empty($data['kCalories']) ? (int) $data['kCalories'] : null,
            'difficulty' => match ((int) ($data['difficulty'] ?? 0)) {
                1 => 'easy',
                3 => 'hard',
                4 => 'challenging',
                default => 'normal',
            },
            'is_vegan' => false,
            'is_vegetarian' => false,
            'is_pescetarian' => false,
            'source' => ($data['source']['name'] ?? '') !== '' ? (string) $data['source']['name'] : 'Chefkoch.de',
            'source_url' => ($data['source']['url'] ?? '') !== '' ? (string) $data['source']['url'] : (string) ($data['siteUrl'] ?? ''),
            'tags' => [],
            'ingredients' => $ingredients,
            'steps' => $steps,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @return array[] one heading row per group header (if non-empty),
     *         followed by its ingredients - real amount/unit/note straight
     *         from the API instead of IngredientLineParser's regex guesses.
     */
    private function mapIngredientGroups(array $groups): array
    {
        usort($groups, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $ingredients = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $header = trim((string) ($group['header'] ?? ''));
            if ($header !== '') {
                $ingredients[] = ['name' => $header, 'amount' => null, 'unit' => null, 'note' => null, 'is_heading' => true];
            }

            $groupIngredients = is_array($group['ingredients'] ?? null) ? $group['ingredients'] : [];
            usort($groupIngredients, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

            foreach ($groupIngredients as $ingredient) {
                if (!is_array($ingredient)) {
                    continue;
                }
                $name = trim((string) ($ingredient['food']['nameSingular'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $unit = trim((string) ($ingredient['unit']['nameSingular'] ?? ''));
                $properties = is_array($ingredient['properties'] ?? null)
                    ? array_values(array_filter(array_map('strval', $ingredient['properties'])))
                    : [];

                $ingredients[] = [
                    'name' => $name,
                    'amount' => isset($ingredient['amount']) && $ingredient['amount'] !== null ? (float) $ingredient['amount'] : null,
                    'unit' => UnitNormalizer::normalize($unit),
                    'note' => $properties !== [] ? implode(', ', $properties) : null,
                    'is_heading' => false,
                ];
            }
        }

        return $ingredients;
    }

    /**
     * @return array[]
     */
    private function mapFreetextIngredients(string $ingredientsText, IngredientLineParser $ingredientParser): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $ingredientsText, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(
            static fn (string $line) => $ingredientParser->parse(trim(str_replace("\t", ' ', $line))),
            $lines ?: []
        );
    }

    /**
     * @return array[] one heading row per section header (if non-empty), followed by its steps.
     */
    private function mapStructuredInstructions(array $sections): array
    {
        usort($sections, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $steps = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $header = trim((string) ($section['header'] ?? ''));
            if ($header !== '') {
                $steps[] = ['instruction' => $header, 'is_heading' => true];
            }

            $sectionSteps = is_array($section['steps'] ?? null) ? $section['steps'] : [];
            usort($sectionSteps, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

            foreach ($sectionSteps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $text = trim((string) ($step['text'] ?? ''));
                if ($text !== '') {
                    $steps[] = ['instruction' => $text, 'is_heading' => false];
                }
            }
        }

        return $steps;
    }

    /**
     * @return array[]
     */
    private function mapFreetextInstructions(string $instructions): array
    {
        $paragraphs = preg_split('/\r?\n\s*\r?\n/', trim($instructions), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(
            static fn (string $p) => ['instruction' => trim(preg_replace('/\s+/', ' ', $p)), 'is_heading' => false],
            $paragraphs ?: []
        );
    }

    public function fetchImageBytes(string $imageUrl, string $token): ?string
    {
        try {
            // Sent with the authenticated token attached (unlike
            // RecipeController::importJson()'s plain file_get_contents()) -
            // Chefkoch's CDN is documented to 403 a bare unauthenticated
            // request (done.md), so this at least gives it a real session
            // to work with; still allowed to simply fail (see below).
            $result = ($this->sender)('GET', $imageUrl, ['x-chefkoch-api-token: ' . $token], null);
        } catch (Throwable $e) {
            error_log('Kochbuch: Chefkoch image fetch failed: ' . $e->getMessage());

            return null;
        }

        if ($result['status'] < 200 || $result['status'] >= 300 || $result['body'] === '') {
            return null;
        }

        return $result['body'];
    }

    /**
     * @return array<string,mixed>
     */
    private function apiGet(string $url, string $token): array
    {
        try {
            $result = ($this->sender)('GET', $url, ['x-chefkoch-api-token: ' . $token, 'Accept: application/json'], null);
        } catch (Throwable $e) {
            error_log('Kochbuch: Chefkoch API request failed: ' . $e->getMessage());
            throw new ApiException('Could not reach Chefkoch.de.', 502, 'chefkoch.unreachable');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            error_log('Kochbuch: Chefkoch API request to ' . $url . ' returned HTTP ' . $result['status']);
            throw new ApiException('Chefkoch.de request failed.', 502, 'chefkoch.api_request_failed');
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new ApiException('Chefkoch.de returned an unexpected response.', 502, 'chefkoch.api_response_unrecognized');
        }

        return $decoded;
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private static function curlSender(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            // A plain default User-Agent gets blocked by some of Chefkoch's
            // anti-bot measures even for otherwise-legitimate requests
            // (done.md's image-403 note) - a realistic browser UA is the
            // minimum, not a full workaround.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $headerLine) use (&$responseHeaders) {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($headerLine);
            },
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $responseBody];
    }
}
