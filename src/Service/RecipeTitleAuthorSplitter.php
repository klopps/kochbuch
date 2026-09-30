<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Imported recipe titles (Chefkoch.de and generic schema.org/JSON-LD import
 * alike) very often end with " von <Benutzername>" - the source site's own
 * convention for crediting whoever submitted/shared the recipe, tacked onto
 * the title text itself rather than carried as separate metadata. Kept as
 * part of the title it reads as noise ("Kartoffelsalat von maxmuster123"
 * instead of just "Kartoffelsalat"), so this strips that trailing " von
 * <Name>" and returns it instead as a "Rezept von <Name>" line appended to
 * the description, where it belongs as a note about provenance.
 *
 * Only ever strips the LAST " von <Name>" segment (greedy match), since a
 * legitimate title occasionally contains "von" earlier as an ordinary
 * German word (e.g. "Consommé von Tomate von maxmuster123" keeps "Consommé
 * von Tomate" as the name and only peels off the trailing "maxmuster123").
 * This is a deliberately blind heuristic, not a lookup against a known
 * author name - a title that itself ends in "von <ingredient>" (e.g.
 * "Consommé von Tomate" with no real suffix after it) is indistinguishable
 * from the credit convention this exists to strip, and gets treated the
 * same way. Accepted trade-off (confirmed with the user 2026-09-30): this
 * false-positive case is rare compared to how reliably Chefkoch users
 * credit themselves this way in their own titles.
 */
final class RecipeTitleAuthorSplitter
{
    /**
     * @return array{name: string, description: ?string}
     */
    public static function split(string $name, ?string $description): array
    {
        if (preg_match('/^(.+)\s+von\s+(\S.*)$/u', $name, $m) !== 1) {
            return ['name' => $name, 'description' => $description];
        }

        $cleanName = trim($m[1]);
        $author = trim($m[2]);
        if ($cleanName === '' || $author === '') {
            return ['name' => $name, 'description' => $description];
        }

        $note = 'Rezept von ' . $author;
        $description = $description !== null && $description !== ''
            ? $description . "\n\n" . $note
            : $note;

        return ['name' => $cleanName, 'description' => $description];
    }
}
