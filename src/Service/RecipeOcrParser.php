<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Turns a VisionOcrService::recognizeDocument() result into a best-effort
 * recipe draft (todo.md "Importing Photos of Handwritten Recipes") - name/
 * ingredients/steps in the same shape RecipeController::validate() expects,
 * ready to pre-fill the existing create form. Deliberately conservative:
 * this only reduces typing, it never writes a recipe by itself - every
 * field it produces still goes through the normal editable form, so an
 * OCR/parsing mistake is caught by the same review a manually-entered
 * recipe already gets. Anything it can't confidently place is appended to
 * `notes` rather than dropped, so no recognized text is ever silently lost.
 *
 * Real handwritten recipe cards (verified against actual photos, not just
 * synthetic examples) turned out to be two printed columns side by side -
 * "Zutaten" on the left, "Zubereitung" on the right. Vision's own flat
 * reading order interleaves both columns' lines unpredictably, so a plain
 * top-to-bottom line scan (the original version of this class) sometimes
 * never found the ingredients section at all, and always mis-split
 * multi-line handwritten instructions into one bogus "step" per OCR line.
 * parse() therefore first tries to split the page into left/right columns
 * using each paragraph's own bounding box (see splitByColumns()), and only
 * falls back to plain single-stream line parsing when that split isn't
 * confident (e.g. a single-column layout, or a photo with no headings).
 */
final class RecipeOcrParser
{
    private const INGREDIENTS_HEADING = '/^(zutaten|ingredients)\b/iu';
    private const STEPS_HEADING = '/^(zubereitung|anleitung|schritte|instructions|steps|directions)\b/iu';
    private const NAME_PREFIX = '/^(rezeptname|recipe\s*name|name)\s*:?\s*/iu';

    private const UNIT_WORDS = [
        'g', 'kg', 'mg', 'ml', 'l', 'el', 'tl', 'stk', 'stück', 'prise', 'bund',
        'dose', 'pkg', 'packung', 'scheibe', 'tasse', 'zehe', 'bündel',
        'cup', 'tbsp', 'tsp', 'oz', 'lb', 'pinch', 'clove', 'slice', 'can', 'pack',
    ];

    // Recipe names aren't paragraphs - a mis-OCR'd multi-line blob shouldn't
    // become the name, so a suspiciously long first line is left blank
    // rather than guessed.
    private const MAX_NAME_LENGTH = 120;

    // A stray line-noise label seen on real recipe-card photos (the
    // template's "Zutaten für ___ Personen:" splits "Personen:" onto its
    // own OCR paragraph, landing in the ingredients column with no
    // ingredient content of its own) - filtered rather than becoming a
    // bogus ingredient row.
    private const INGREDIENT_NOISE_LINES = ['personen', 'portionen', 'people', 'servings'];

    // A paragraph whose bounding box spans at least this fraction of the
    // page width is treated as a merged/full-width line (Vision's own
    // block detection occasionally fails to separate two columns that sit
    // on the same row) rather than confidently assigned to one column -
    // included in both rather than guessing which side it belongs to.
    private const WIDE_PARAGRAPH_RATIO = 0.7;

    /**
     * @param array{text: string, paragraphs?: array<int, array{text: string, xMin: float, xMax: float, yTop: float}>, pageWidth?: float} $document
     * @return array{name: ?string, ingredients: array<int, array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}>, steps: array<int, array{instruction: string, is_heading: bool}>, notes: ?string}
     */
    public function parse(array $document): array
    {
        $flatText = (string) ($document['text'] ?? '');
        $paragraphs = $document['paragraphs'] ?? [];
        $pageWidth = (float) ($document['pageWidth'] ?? 0);

        if ($paragraphs !== [] && $pageWidth > 0) {
            $split = $this->splitByColumns($paragraphs, $pageWidth);
            if ($split !== null) {
                [$nameText, $ingredientsText, $stepsText] = $split;

                return [
                    'name' => $this->extractName(explode("\n", $nameText)),
                    'ingredients' => $this->parseIngredients($this->extractZone($ingredientsText, self::INGREDIENTS_HEADING)),
                    'steps' => $this->parseSteps($this->extractZone($stepsText, self::STEPS_HEADING)),
                    'notes' => null,
                ];
            }
        }

        return $this->parseSingleStream($flatText);
    }

    /**
     * Groups paragraphs into a name/preamble zone (everything above the
     * first heading paragraph) and left/right columns (split at the page's
     * horizontal midpoint), then decides which column is the ingredients
     * one and which is the steps one by checking which contains which
     * heading. Returns null when the split isn't confident enough to trust
     * (no heading found on either side, or both headings landed on the
     * same side) - the caller falls back to plain single-stream parsing.
     *
     * @param array<int, array{text: string, xMin: float, xMax: float, yTop: float}> $paragraphs
     * @return array{0: string, 1: string, 2: string}|null [nameText, ingredientsText, stepsText]
     */
    private function splitByColumns(array $paragraphs, float $pageWidth): ?array
    {
        usort($paragraphs, static fn (array $a, array $b) => $a['yTop'] <=> $b['yTop']);

        $keywordY = null;
        foreach ($paragraphs as $p) {
            foreach (explode("\n", $p['text']) as $line) {
                $trimmed = trim($line);
                if (preg_match(self::INGREDIENTS_HEADING, $trimmed) === 1 || preg_match(self::STEPS_HEADING, $trimmed) === 1) {
                    $keywordY = $keywordY === null ? $p['yTop'] : min($keywordY, $p['yTop']);
                }
            }
        }

        $midX = $pageWidth / 2;
        $wideWidth = $pageWidth * self::WIDE_PARAGRAPH_RATIO;
        $preamble = [];
        $left = [];
        $right = [];
        foreach ($paragraphs as $p) {
            if ($keywordY !== null && $p['yTop'] < $keywordY) {
                $preamble[] = $p['text'];
                continue;
            }
            $width = $p['xMax'] - $p['xMin'];
            if ($width >= $wideWidth) {
                $left[] = $p['text'];
                $right[] = $p['text'];
                continue;
            }
            if (($p['xMin'] + $p['xMax']) / 2 < $midX) {
                $left[] = $p['text'];
            } else {
                $right[] = $p['text'];
            }
        }

        $leftText = implode("\n", $left);
        $rightText = implode("\n", $right);

        $leftHasIngredients = $this->containsHeading($leftText, self::INGREDIENTS_HEADING);
        $leftHasSteps = $this->containsHeading($leftText, self::STEPS_HEADING);
        $rightHasIngredients = $this->containsHeading($rightText, self::INGREDIENTS_HEADING);
        $rightHasSteps = $this->containsHeading($rightText, self::STEPS_HEADING);

        $nameText = implode("\n", $preamble);
        if ($leftHasIngredients && $rightHasSteps) {
            return [$nameText, $leftText, $rightText];
        }
        if ($rightHasIngredients && $leftHasSteps) {
            return [$nameText, $rightText, $leftText];
        }

        return null;
    }

    private function containsHeading(string $text, string $pattern): bool
    {
        foreach (explode("\n", $text) as $line) {
            if (preg_match($pattern, trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: ?string, ingredients: array[], steps: array[], notes: ?string}
     */
    private function parseSingleStream(string $rawText): array
    {
        $lines = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $rawText)));

        $ingredientsStart = null;
        $stepsStart = null;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($ingredientsStart === null && preg_match(self::INGREDIENTS_HEADING, $trimmed) === 1) {
                $ingredientsStart = $i;
            } elseif ($stepsStart === null && preg_match(self::STEPS_HEADING, $trimmed) === 1) {
                $stepsStart = $i;
            }
        }

        if ($ingredientsStart === null && $stepsStart === null) {
            return ['name' => null, 'ingredients' => [], 'steps' => [], 'notes' => $this->joinNonBlank($lines)];
        }

        $nameZoneEnd = $ingredientsStart ?? $stepsStart;
        $ingredientsZoneStart = $ingredientsStart !== null ? $ingredientsStart + 1 : null;
        $ingredientsZoneEnd = $stepsStart ?? count($lines);
        $stepsZoneStart = $stepsStart !== null ? $stepsStart + 1 : null;

        $name = $this->extractName(array_slice($lines, 0, $nameZoneEnd));

        $ingredients = $ingredientsZoneStart !== null
            ? $this->parseIngredients(array_slice($lines, $ingredientsZoneStart, $ingredientsZoneEnd - $ingredientsZoneStart))
            : [];

        $steps = $stepsZoneStart !== null
            ? $this->parseSteps(array_slice($lines, $stepsZoneStart))
            : [];

        return ['name' => $name, 'ingredients' => $ingredients, 'steps' => $steps, 'notes' => null];
    }

    /**
     * Finds the first line matching $headingPattern and returns everything
     * after it as the zone's lines - if the heading line itself has
     * trailing text past the keyword (e.g. "Zutaten: 200g Mehl" all on one
     * OCR line), that remainder becomes the zone's first line instead of
     * being discarded. Falls back to treating the whole text as the zone
     * if no heading line is found (defensive - callers only reach here
     * once a heading was already confirmed present somewhere in $text).
     *
     * @return string[]
     */
    private function extractZone(string $text, string $headingPattern): array
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (preg_match($headingPattern, $trimmed) !== 1) {
                continue;
            }
            $remainder = trim((string) preg_replace($headingPattern, '', $trimmed));
            $remainder = ltrim($remainder, ":-\t ");
            // Printed templates often continue the heading with another
            // label word on the same OCR line (e.g. "Zutaten für" - "für"
            // is leftover here, not real content) - only trust the
            // remainder as an actual ingredient/step when it looks like
            // one (contains a digit) or is long enough to plausibly be a
            // real word rather than a stray connector.
            $looksLikeContent = preg_match('/\d/', $remainder) === 1 || mb_strlen($remainder) >= 8;
            $rest = array_slice($lines, $i + 1);

            return ($remainder !== '' && $looksLikeContent) ? array_merge([$remainder], $rest) : $rest;
        }

        return $lines;
    }

    private function extractName(array $zoneLines): ?string
    {
        foreach ($zoneLines as $line) {
            $trimmed = trim((string) preg_replace(self::NAME_PREFIX, '', trim($line)));
            if ($trimmed === '') {
                continue;
            }

            return mb_strlen($trimmed) <= self::MAX_NAME_LENGTH ? $trimmed : null;
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}>
     */
    private function parseIngredients(array $zoneLines): array
    {
        $ingredients = [];
        foreach ($zoneLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || in_array(mb_strtolower(rtrim($trimmed, ':')), self::INGREDIENT_NOISE_LINES, true)) {
                continue;
            }

            $ingredients[] = $this->parseIngredientLine($trimmed);
        }

        return $ingredients;
    }

    private function parseIngredientLine(string $line): array
    {
        // Leading amount (comma or dot decimal - comma is just German
        // display convention, normalized to "." for storage, same as the
        // existing PDF export's ingredient-line handling), then an
        // optional unit word, then the rest of the line as the name.
        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s+([^\s]+)?\s*(.*)$/u', $line, $m) === 1) {
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

    /**
     * Handwritten instructions wrap across many physical/OCR lines per
     * logical step, unlike ingredients (one item per line) - so lines are
     * joined into a single blob first, then split back into steps either
     * at explicit numbering ("1. ...", "2) ...") when present, or
     * otherwise at sentence-ending punctuation. One OCR line was never a
     * reliable step boundary (verified against real photos: it produced a
     * dozen fragments for what was really 3-4 actual instructions).
     *
     * @return array<int, array{instruction: string, is_heading: bool}>
     */
    private function parseSteps(array $zoneLines): array
    {
        $joined = trim(implode(' ', array_filter(array_map('trim', $zoneLines), static fn (string $l) => $l !== '')));
        if ($joined === '') {
            return [];
        }

        $numberedParts = preg_split('/(?=(?:^|\s)\d{1,2}[.)]\s)/u', $joined, -1, PREG_SPLIT_NO_EMPTY);
        $parts = (is_array($numberedParts) && count($numberedParts) > 1)
            ? $numberedParts
            : preg_split('/(?<=[.!?])\s+/u', $joined, -1, PREG_SPLIT_NO_EMPTY);

        $steps = [];
        foreach ($parts ?: [] as $part) {
            $instruction = trim((string) preg_replace('/^(\d{1,2}[.)]|[a-zA-Z][.)])\s*/', '', trim($part)));
            if (mb_strlen($instruction) < 2) {
                continue;
            }
            $steps[] = ['instruction' => $instruction, 'is_heading' => false];
        }

        return $steps;
    }

    private function joinNonBlank(array $lines): ?string
    {
        $nonBlank = array_values(array_filter(array_map('trim', $lines), static fn (string $l) => $l !== ''));

        return $nonBlank === [] ? null : implode("\n", $nonBlank);
    }
}
