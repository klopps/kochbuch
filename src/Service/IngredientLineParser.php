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

    // Tried in this order so the longer alternatives (a mixed number, then
    // a bare fraction) win over the plain-decimal case for input like
    // "1 1/2" or "1/2" - a fraction is a real number (e.g. "1/2" -> 0.5),
    // not a unit/name token, same as a decimal amount.
    private const AMOUNT_PATTERN = '\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?';

    /**
     * @return array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}
     */
    public function parse(string $line): array
    {
        $line = trim($line);

        // Leading amount - a comma/dot decimal (comma is just German
        // display convention, normalized to "." for storage, same as the
        // existing PDF export's ingredient-line handling), a simple
        // fraction ("1/2"), or a mixed number ("1 1/2") - then an optional
        // unit word. The separator between amount and unit is optional
        // (`\s*`, not `\s+`): OCR/imported text very often glues the unit
        // straight onto the number ("150g" instead of "150 g"), and the
        // exact same UNIT_WORDS check below already tells a real unit
        // apart from a glued-on ingredient name either way - then the rest
        // of the line as the name.
        if (preg_match('/^(' . self::AMOUNT_PATTERN . ')\s*([^\s]+)?\s*(.*)$/u', $line, $m) === 1) {
            $amount = self::parseAmountToken($m[1]);
            $candidateUnit = isset($m[2]) ? mb_strtolower($m[2]) : '';
            $candidateUnit = rtrim($candidateUnit, '.');

            if ($candidateUnit !== '' && in_array($candidateUnit, self::UNIT_WORDS, true)) {
                $name = trim($m[3]);
                if ($name !== '') {
                    return ['name' => $name, 'amount' => $amount, 'unit' => UnitNormalizer::normalize($m[2]), 'note' => null, 'is_heading' => false];
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

    /**
     * Converts a token already matched by AMOUNT_PATTERN - a mixed number
     * ("1 1/2"), a simple fraction ("1/2", "1/4"), or a plain decimal - into
     * its numeric value (e.g. "1/2" -> 0.5, "1/4" -> 0.25).
     */
    private static function parseAmountToken(string $token): float
    {
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $token, $m) === 1) {
            return (float) $m[1] + ((float) $m[2] / (float) $m[3]);
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $token, $m) === 1) {
            return (float) $m[1] / (float) $m[2];
        }

        return (float) str_replace(',', '.', $token);
    }
}
