<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Normalizes the unit of an imported ingredient (todo.md "TL/EL beim Import
 * immer groß"): Esslöffel/Teelöffel are always written "EL"/"TL" in
 * Kochbuch, however the source spelled them ("el", "El.", "tl" ...). Used by
 * every import path that produces ingredient units - IngredientLineParser
 * (photo OCR fallback, schema.org/JSON-LD and JSON file import),
 * GeminiRecipeExtractor (photo/text/web-page recognition) and
 * ChefkochImportService. Any other unit is returned unchanged (trimmed).
 */
final class UnitNormalizer
{
    private const UPPERCASE = ['el' => 'EL', 'tl' => 'TL'];

    public static function normalize(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $unit = trim($unit);
        if ($unit === '') {
            return null;
        }
        $key = mb_strtolower(rtrim($unit, '.'));

        return self::UPPERCASE[$key] ?? $unit;
    }
}
