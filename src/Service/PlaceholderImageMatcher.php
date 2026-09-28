<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Pure keyword matching for todo.md "Placeholders for Missing Images" - no
 * DB access, so it's trivial to unit test in isolation. RecipeRepository
 * calls this with its own already-fetched placeholder list and applies the
 * is_default fallback itself (this class only knows about keyword matches).
 */
final class PlaceholderImageMatcher
{
    /**
     * @param string[] $tags already in the order they should be checked in
     * @param array<int,array{filename:string,keywords:array<int,array{locale:string,keyword:string}>}> $placeholders
     */
    public static function match(string $recipeName, array $tags, array $placeholders): ?string
    {
        // Sorted once, longest keyword first: a substring match on a
        // shorter, less specific keyword (e.g. "eis" inside "Reisbratling")
        // must never win over a longer, more specific one ("Reis") that
        // also matches the same text - usort() is stable since PHP 8.0, so
        // keywords of equal length still keep their original (placeholder
        // id, then keyword) order.
        $entries = self::flattenKeywordsByDescendingLength($placeholders);

        $filename = self::firstMatch($entries, $recipeName);
        if ($filename !== null) {
            return $filename;
        }

        foreach ($tags as $tag) {
            $filename = self::firstMatch($entries, $tag);
            if ($filename !== null) {
                return $filename;
            }
        }

        return null;
    }

    /**
     * @param array<int,array{filename:string,keywords:array<int,array{locale:string,keyword:string}>}> $placeholders
     * @return array<int,array{keyword:string,filename:string}>
     */
    private static function flattenKeywordsByDescendingLength(array $placeholders): array
    {
        $entries = [];
        foreach ($placeholders as $placeholder) {
            foreach ($placeholder['keywords'] as $keyword) {
                if ($keyword['keyword'] !== '') {
                    $entries[] = ['keyword' => $keyword['keyword'], 'filename' => $placeholder['filename']];
                }
            }
        }

        usort($entries, static fn (array $a, array $b) => mb_strlen($b['keyword']) <=> mb_strlen($a['keyword']));

        return $entries;
    }

    /**
     * @param array<int,array{keyword:string,filename:string}> $entries already sorted longest-keyword-first
     */
    private static function firstMatch(array $entries, string $haystack): ?string
    {
        foreach ($entries as $entry) {
            if (mb_stripos($haystack, $entry['keyword']) !== false) {
                return $entry['filename'];
            }
        }

        return null;
    }
}
