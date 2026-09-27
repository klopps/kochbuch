<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Tolerant "freetext ingredient line" -> {name, amount, unit, note}
 * splitter, shared by every recipe-import path that starts from plain
 * ingredient strings rather than already-structured data: RecipeOcrParser
 * (handwritten photo OCR) and SchemaOrgRecipeParser (schema.org Recipe
 * JSON-LD's `recipeIngredient` array, e.g. "200 g Mehl", "3-4 EL Rosenwasser").
 * Deliberately conservative - an unrecognized leading token is kept as part
 * of the name rather than guessed as a unit, and a line with no leading
 * number is left entirely to the user.
 */
final class IngredientLineParser
{
    private const UNIT_WORDS = [
        'g', 'kg', 'mg', 'ml', 'l', 'cl', 'dl', 'el', 'tl', 'stk', 'stück', 'prise', 'bund',
        'dose', 'pkg', 'packung', 'scheibe', 'tasse', 'zehe', 'bündel',
        'cup', 'tbsp', 'tsp', 'oz', 'lb', 'pinch', 'clove', 'slice', 'can', 'pack',
    ];

    /**
     * @return array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}
     */
    public function parse(string $line): array
    {
        $line = trim($line);

        // Leading amount (comma or dot decimal - comma is just German
        // display convention, normalized to "." for storage, same as the
        // existing PDF export's ingredient-line handling), then an
        // optional unit word - the separator between amount and unit is
        // optional (`\s*`, not `\s+`): OCR/imported text very often glues
        // the unit straight onto the number ("150g" instead of "150 g"),
        // and the exact same UNIT_WORDS check below already tells a real
        // unit apart from a glued-on ingredient name either way - then the
        // rest of the line as the name.
        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*([^\s]+)?\s*(.*)$/u', $line, $m) === 1) {
            $amount = (float) str_replace(',', '.', $m[1]);
            $candidateUnit = isset($m[2]) ? mb_strtolower($m[2]) : '';
            $candidateUnit = rtrim($candidateUnit, '.');

            if ($candidateUnit !== '' && in_array($candidateUnit, self::UNIT_WORDS, true)) {
                $name = trim($m[3]);
                if ($name !== '') {
                    return ['name' => $name, 'amount' => $amount, 'unit' => $m[2], 'note' => null, 'is_heading' => false];
                }
            }

            // No recognized unit word - the "unit" token was actually part
            // of the ingredient name (e.g. "2 Zwiebeln").
            $name = trim(($m[2] ?? '') . ' ' . $m[3]);
            if ($name !== '') {
                return ['name' => $name, 'amount' => $amount, 'unit' => null, 'note' => null, 'is_heading' => false];
            }
        }

        // No leading number at all (e.g. "Salz, Pfeffer") - left entirely
        // to the user rather than guessing an amount/unit split.
        return ['name' => $line, 'amount' => null, 'unit' => null, 'note' => null, 'is_heading' => false];
    }
}
