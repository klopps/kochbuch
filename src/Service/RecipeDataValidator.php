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
    /** Backarten, in public/js/helper.js's BAKE_MODES mirrored by hand. */
    /** Rezepttypen, in public/js/helper.js's RECIPE_TYPES mirrored by hand. */
    public const RECIPE_TYPES = ['general', 'baking'];
    public const BAKE_MODES = ['convection', 'top_bottom', 'top', 'bottom', 'grill', 'convection_grill'];
    private const MAX_BAKE_TEMPERATURE = 800;

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

        // Backart: null/'' = "-nein-" (not baked) - then no temperature
        // either, it would be meaningless and is never shown.
        $bakeMode = $this->nullableString($body['bake_mode'] ?? null);
        if ($bakeMode !== null && !in_array($bakeMode, self::BAKE_MODES, true)) {
            throw new ValidationException('Invalid bake mode.', 'recipe.invalid_bake_mode');
        }
        $bakeTemperature = $bakeMode !== null ? $this->nullableInt($body['bake_temperature'] ?? null) : null;
        if ($bakeTemperature !== null && ($bakeTemperature < 0 || $bakeTemperature > self::MAX_BAKE_TEMPERATURE)) {
            throw new ValidationException('Temperature must be between 0 and 800 °C.', 'recipe.invalid_bake_temperature');
        }

        $recipeType = $this->nullableString($body['recipe_type'] ?? null) ?? 'general';
        if (!in_array($recipeType, self::RECIPE_TYPES, true)) {
            throw new ValidationException('Invalid recipe type.', 'recipe.invalid_recipe_type');
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
            'recipe_type' => $recipeType,
            'name' => $name,
            'description' => $this->nullableString($body['description'] ?? null),
            'servings' => $servings,
            'difficulty' => $difficulty,
            'prep_time_minutes' => $this->nullableInt($body['prep_time_minutes'] ?? null),
            'rest_time_minutes' => $this->nullableInt($body['rest_time_minutes'] ?? null),
            'cook_time_minutes' => $this->nullableInt($body['cook_time_minutes'] ?? null),
            'notes' => $this->nullableString($body['notes'] ?? null),
            'calories' => $this->nullableInt($body['calories'] ?? null),
            'bake_mode' => $bakeMode,
            'bake_temperature' => $bakeTemperature,
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
