<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use DateInterval;
use InvalidArgumentException;

/**
 * Maps a decoded schema.org Recipe JSON-LD document (todo.md "Importing
 * schema.org Recipe JSON-LD") into the plain array shape
 * RecipeController::validate() expects as its $body parameter - the exact
 * same target shape RecipeOcrParser produces, and for the same reason:
 * `recipeIngredient` is freetext ("200 g Mehl"), so ingredient-line
 * splitting is delegated to the same IngredientLineParser both classes
 * share rather than being duplicated.
 *
 * Deliberately tolerant of the handful of real-world shape variations
 * schema.org Recipe actually allows (verified against real JSON-LD files,
 * not just the spec): `recipeInstructions` as plain strings, {@type:
 * HowToStep} objects, or nested {@type: HowToSection, itemListElement:
 * [...]} sections (rendered as heading rows); `recipeYield`/`image` as a
 * bare string, a number, or an array of either; ISO 8601 durations
 * ("PT1H30M") for prep/cook time via DateInterval rather than a hand-rolled
 * regex.
 *
 * Only `name` is required - anything else missing/malformed is simply left
 * out of the mapped result rather than failing the whole import, since a
 * partial recipe the user can still fill in by hand afterward is more
 * useful than rejecting the file outright.
 */
final class SchemaOrgRecipeParser
{
    public function __construct(private readonly IngredientLineParser $ingredientLineParser = new IngredientLineParser())
    {
    }

    /**
     * @return array{name: string, description: ?string, servings: ?int, prep_time_minutes: ?int, cook_time_minutes: ?int, rest_time_minutes: ?int, calories: ?int, is_vegan: bool, is_vegetarian: bool, is_pescetarian: bool, source: ?string, source_url: ?string, tags: string[], ingredients: array[], steps: array[], image_url: ?string}
     * @throws InvalidArgumentException when $json has no usable recipe name
     */
    public function parse(array $json): array
    {
        $name = trim((string) ($json['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Missing "name".');
        }

        $prepMinutes = $this->parseDurationMinutes($json['prepTime'] ?? null);
        $cookMinutes = $this->parseDurationMinutes($json['cookTime'] ?? null);
        $totalMinutes = $this->parseDurationMinutes($json['totalTime'] ?? null);
        $restMinutes = null;
        if ($totalMinutes !== null && $prepMinutes !== null && $cookMinutes !== null) {
            $remainder = $totalMinutes - $prepMinutes - $cookMinutes;
            $restMinutes = $remainder > 0 ? $remainder : null;
        }

        // todo.md: imported titles often carry a trailing " von
        // <Benutzername>" (the source site's own credit convention) -
        // strip it from the name and keep it as a "Rezept von ..." note in
        // the description instead.
        ['name' => $name, 'description' => $description] = RecipeTitleAuthorSplitter::split($name, $this->nullableString($json['description'] ?? null));

        $diets = $this->normalizeToStringList($json['suitableForDiet'] ?? null);

        // todo.md "Unambiguity of recipe attributes" - vegan/vegetarian/
        // pescetarian are mutually exclusive on a recipe. Real-world
        // schema.org markup sometimes tags a recipe with more than one of
        // these (redundantly, or just sloppily), so pick at most one here,
        // most restrictive first, rather than letting RecipeController::
        // validate() reject the whole import over ambiguous source data.
        $isVegan = $this->containsDiet($diets, 'vegandiet');
        $isVegetarian = !$isVegan && $this->containsDiet($diets, 'vegetariandiet');
        $isPescetarian = !$isVegan && !$isVegetarian && $this->containsDiet($diets, 'pescetariandiet');

        return [
            'name' => $name,
            'description' => $description,
            'servings' => $this->parseServings($json['recipeYield'] ?? null),
            'prep_time_minutes' => $prepMinutes,
            'cook_time_minutes' => $cookMinutes,
            'rest_time_minutes' => $restMinutes,
            'calories' => $this->parseCalories($json['nutrition']['calories'] ?? null),
            'is_vegan' => $isVegan,
            'is_vegetarian' => $isVegetarian,
            'is_pescetarian' => $isPescetarian,
            'source' => $this->extractSource($json),
            'source_url' => $this->nullableString($json['url'] ?? null),
            'tags' => $this->extractTags($json),
            'ingredients' => $this->parseIngredients($json['recipeIngredient'] ?? null),
            'steps' => $this->parseInstructions($json['recipeInstructions'] ?? null),
            'image_url' => $this->extractImageUrl($json['image'] ?? null),
        ];
    }

    private function parseServings(mixed $yield): ?int
    {
        $candidate = is_array($yield) ? ($yield[0] ?? null) : $yield;
        if (is_int($candidate)) {
            return $candidate > 0 ? $candidate : null;
        }
        if (is_string($candidate) && preg_match('/\d+/', $candidate, $m) === 1) {
            $n = (int) $m[0];

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private function parseCalories(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\d+/', $value, $m) === 1) {
            return (int) $m[0];
        }

        return null;
    }

    private function parseDurationMinutes(mixed $duration): ?int
    {
        if (!is_string($duration) || $duration === '') {
            return null;
        }

        try {
            $interval = new DateInterval($duration);
        } catch (\Exception) {
            return null;
        }

        $minutes = ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * @return string[]
     */
    private function normalizeToStringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        return [];
    }

    /**
     * @param string[] $diets
     */
    private function containsDiet(array $diets, string $needle): bool
    {
        foreach ($diets as $diet) {
            if (str_contains(mb_strtolower($diet), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractSource(array $json): ?string
    {
        foreach (['author', 'publisher'] as $key) {
            $value = $json[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value) && is_string($value['name'] ?? null) && trim($value['name']) !== '') {
                return trim($value['name']);
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractTags(array $json): array
    {
        $tags = [];
        $keywords = $json['keywords'] ?? null;
        if (is_string($keywords)) {
            $tags = array_merge($tags, array_map('trim', explode(',', $keywords)));
        } elseif (is_array($keywords)) {
            $tags = array_merge($tags, array_filter($keywords, 'is_string'));
        }

        $category = $json['recipeCategory'] ?? null;
        if (is_string($category)) {
            $tags[] = trim($category);
        } elseif (is_array($category)) {
            $tags = array_merge($tags, array_filter($category, 'is_string'));
        }

        $tags = array_values(array_unique(array_filter(array_map('trim', $tags), static fn (string $t) => $t !== '')));

        return $tags;
    }

    /**
     * @return array<int, array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}>
     */
    private function parseIngredients(mixed $recipeIngredient): array
    {
        $lines = is_string($recipeIngredient) ? [$recipeIngredient] : (is_array($recipeIngredient) ? $recipeIngredient : []);

        $ingredients = [];
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }
            $ingredients[] = $this->ingredientLineParser->parse($line);
        }

        return $ingredients;
    }

    /**
     * Handles every real-world `recipeInstructions` shape: a single
     * freetext string (split on newlines), an array of plain strings, an
     * array of {@type: HowToStep, text: ...} objects, and an array of
     * {@type: HowToSection, name: ..., itemListElement: [...]} sections
     * (each section's name becomes a heading row, its steps follow).
     *
     * @return array<int, array{instruction: string, is_heading: bool}>
     */
    private function parseInstructions(mixed $recipeInstructions): array
    {
        if (is_string($recipeInstructions)) {
            $lines = array_filter(array_map('trim', explode("\n", $recipeInstructions)), static fn (string $l) => $l !== '');

            return array_map(static fn (string $l) => ['instruction' => $l, 'is_heading' => false], array_values($lines));
        }

        if (!is_array($recipeInstructions)) {
            return [];
        }

        $steps = [];
        foreach ($recipeInstructions as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $steps[] = ['instruction' => $trimmed, 'is_heading' => false];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $type = $item['@type'] ?? null;
            if ($type === 'HowToSection') {
                $sectionName = trim((string) ($item['name'] ?? ''));
                if ($sectionName !== '') {
                    $steps[] = ['instruction' => $sectionName, 'is_heading' => true];
                }
                foreach ($this->parseInstructions($item['itemListElement'] ?? []) as $nested) {
                    $steps[] = $nested;
                }
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            if ($text !== '') {
                $steps[] = ['instruction' => $text, 'is_heading' => false];
            }
        }

        return $steps;
    }

    private function extractImageUrl(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }
        if (is_array($image)) {
            // Either a plain array of URL strings, or an array of/single
            // ImageObject(s) with their own "url" property.
            $first = array_is_list($image) ? ($image[0] ?? null) : $image;
            if (is_string($first)) {
                return $first;
            }
            if (is_array($first) && is_string($first['url'] ?? null)) {
                return $first['url'];
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
