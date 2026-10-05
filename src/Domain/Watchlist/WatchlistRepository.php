<?php

declare(strict_types=1);

namespace Kochbuch\Domain\Watchlist;

use PDO;

/**
 * Each user's ordered "Merkliste" of recipes to cook next (todo.md
 * "Watchlist", table watchlist_entry). Only ids and order live here - the
 * recipes themselves are loaded through RecipeRepository::findMany().
 */
final class WatchlistRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return int[] recipe ids in list order
     */
    public function recipeIds(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT recipe_id FROM watchlist_entry WHERE user_id = ? ORDER BY position, created_at');
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function contains(int $userId, int $recipeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM watchlist_entry WHERE user_id = ? AND recipe_id = ?');
        $stmt->execute([$userId, $recipeId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Appends the recipe to the end of the list; already on it = unchanged.
     */
    public function add(int $userId, int $recipeId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO watchlist_entry (user_id, recipe_id, position, created_at)
             SELECT ?, ?, COALESCE(MAX(position), 0) + 1, NOW() FROM watchlist_entry WHERE user_id = ?'
        );
        $stmt->execute([$userId, $recipeId, $userId]);
    }

    public function remove(int $userId, int $recipeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM watchlist_entry WHERE user_id = ? AND recipe_id = ?');
        $stmt->execute([$userId, $recipeId]);
    }

    /**
     * Sets the order to $recipeIds (first = position 1). Ids not on the
     * user's list are ignored; entries missing from $recipeIds keep their
     * relative order after the given ones - so a stale client list can never
     * lose or duplicate an entry.
     *
     * @param int[] $recipeIds
     */
    public function reorder(int $userId, array $recipeIds): void
    {
        $current = $this->recipeIds($userId);
        $ordered = array_values(array_unique(array_filter(array_map('intval', $recipeIds), static fn (int $id) => in_array($id, $current, true))));
        foreach ($current as $id) {
            if (!in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        $stmt = $this->pdo->prepare('UPDATE watchlist_entry SET position = ? WHERE user_id = ? AND recipe_id = ?');
        foreach ($ordered as $index => $id) {
            $stmt->execute([$index + 1, $userId, $id]);
        }
    }
}
