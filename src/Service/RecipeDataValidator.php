<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Kochbuch\Exception\ValidationException;

/**
 * Validates/normalizes a recipe payload (from a create/update request body,
 * or a mapped import result) into the exact shape RecipeRepository::create()/
 * update() expect. Extracted out of RecipeController (which originally had
 * this as a private validate() method) so a second controller - the Chefkoch
 * importer (todo.md "Import aus Kochbuch von Chefkoch.de") - can validate
 * recipes it maps from SchemaOrgRecipeParser the exact same way
 * RecipeController::importJson() already does, without duplicating (and
 * risking drifting from) this logic.
 */
final class RecipeDataValidator
{
    private const DIFFICULTIES = ['easy', 'normal', 'hard', 'challenging'];
    private const VISIBILITIES = ['private', 'internal', 'public'];

    public function validate(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('A recipe name is required.', 'recipe.name_required');
        }

        $difficulty = $body['difficulty'] ?? 'normal';
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new ValidationException('Invalid difficulty.', 'recipe.invalid_difficulty');
        }

        $visibility = $body['visibility'] ?? 'internal';
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new ValidationException('Invalid visibility.', 'recipe.invalid_visibility');
        }

        $servings = (int) ($body['servings'] ?? 4);
        if ($servings < 1) {
            throw new ValidationException('Servings must be at least 1.', 'recipe.invalid_servings');
        }

        // todo.md "Unambiguity of recipe attributes" - vegan/vegetarian/
        // pescetarian are mutually exclusive (a recipe is at most one of
        // them, or none), not independent flags.
        $dietFlagCount = (!empty($body['is_vegan']) ? 1 : 0)
            + (!empty($body['is_vegetarian']) ? 1 : 0)
            + (!empty($body['is_pescetarian']) ? 1 : 0);
        if ($dietFlagCount > 1) {
            throw new ValidationException('A recipe can be at most one of vegan, vegetarian or pescetarian.', 'recipe.ambiguous_diet');
        }

        return [
            'name' => $name,
            'description' => $this->nullableString($body['description'] ?? null),
            'servings' => $servings,
            'difficulty' => $difficulty,
            'prep_time_minutes' => $this->nullableInt($body['prep_time_minutes'] ?? null),
            'rest_time_minutes' => $this->nullableInt($body['rest_time_minutes'] ?? null),
            'cook_time_minutes' => $this->nullableInt($body['cook_time_minutes'] ?? null),
            'notes' => $this->nullableString($body['notes'] ?? null),
            'calories' => $this->nullableInt($body['calories'] ?? null),
            'allergen_info' => $this->nullableString($body['allergen_info'] ?? null),
            'is_vegan' => !empty($body['is_vegan']),
            'is_vegetarian' => !empty($body['is_vegetarian']),
            'is_pescetarian' => !empty($body['is_pescetarian']),
            'source' => $this->nullableString($body['source'] ?? null),
            'source_url' => $this->nullableString($body['source_url'] ?? null),
            'visibility' => $visibility,
            'ingredients' => is_array($body['ingredients'] ?? null) ? $body['ingredients'] : [],
            'steps' => is_array($body['steps'] ?? null) ? $body['steps'] : [],
            'tags' => is_array($body['tags'] ?? null) ? $body['tags'] : [],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
