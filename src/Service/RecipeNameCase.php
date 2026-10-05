<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Fixes recipe names that arrive entirely in capital letters (todo.md
 * "Rezeptnamen in Versalien" - common on printed recipe cards, photos and
 * some websites): "SCHNELLER APFELKUCHEN MIT STREUSELN" becomes "Schneller
 * Apfelkuchen mit Streuseln". Names with any lowercase letter are left
 * exactly as they are - only an all-caps name is treated as a typesetting
 * choice rather than the intended spelling.
 *
 * Proper German capitalization (nouns up, adjectives down) needs to know the
 * word class, which a rule can't - so every word is capitalized except
 * common short connecting words (prepositions, articles, conjunctions in
 * German, English, French and Italian titles). The Gemini import path asks
 * the model for proper capitalization instead and only falls back to this.
 * Short all-caps tokens with digits or no vowel ("BBQ", "XXL", "3D") stay
 * as they are - those are usually abbreviations.
 */
final class RecipeNameCase
{
    private const LOWERCASE_WORDS = [
        // German
        'mit', 'und', 'oder', 'von', 'vom', 'zum', 'zur', 'zu', 'in', 'im', 'ins', 'auf', 'an', 'am',
        'aus', 'nach', 'für', 'ohne', 'über', 'unter', 'vor', 'bei', 'beim', 'nach', 'wie', 'à', 'a',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einem', 'einen', 'eines',
        // English / French / Italian / Spanish
        'with', 'and', 'or', 'of', 'the', 'on', 'for', 'to', 'la', 'le', 'les', 'de', 'du', 'del',
        'della', 'al', 'alla', 'all', 'con', 'e', 'y', 'et',
    ];

    public static function fix(?string $name): ?string
    {
        if ($name === null || !self::isAllCaps($name)) {
            return $name;
        }

        $first = true;

        return (string) preg_replace_callback('/[\p{L}\p{N}\']+/u', static function (array $m) use (&$first): string {
            $word = $m[0];
            $isFirst = $first;
            $first = false;
            if (self::looksLikeAbbreviation($word)) {
                return $word;
            }
            $lower = mb_strtolower($word);
            if (!$isFirst && in_array($lower, self::LOWERCASE_WORDS, true)) {
                return $lower;
            }

            return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        }, $name);
    }

    private static function isAllCaps(string $name): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $name) ?? '';

        return mb_strlen($letters) >= 3 && $letters === mb_strtoupper($letters) && $letters !== mb_strtolower($letters);
    }

    private static function looksLikeAbbreviation(string $word): bool
    {
        if (preg_match('/\p{N}/u', $word) === 1 && mb_strlen($word) <= 4) {
            return true;
        }

        return mb_strlen($word) >= 2 && mb_strlen($word) <= 4 && preg_match('/[AEIOUÄÖÜYaeiouäöüy]/u', $word) !== 1;
    }
}
