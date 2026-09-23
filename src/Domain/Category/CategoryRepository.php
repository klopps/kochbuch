<?php

declare(strict_types=1);

namespace Kochbuch\Domain\Category;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO category (user_id, name, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$userId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE category SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM category WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM category WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->cast($row);
    }

    /**
     * @return array[] each with a `recipe_count`
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM category_recipe cr WHERE cr.category_id = c.id) AS recipe_count
             FROM category c WHERE c.user_id = ? ORDER BY c.name'
        );
        $stmt->execute([$userId]);

        return array_map(fn (array $row) => $this->cast($row, true), $stmt->fetchAll());
    }

    public function addRecipe(int $categoryId, int $recipeId): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO category_recipe (category_id, recipe_id) VALUES (?, ?)');
        $stmt->execute([$categoryId, $recipeId]);
    }

    public function removeRecipe(int $categoryId, int $recipeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM category_recipe WHERE category_id = ? AND recipe_id = ?');
        $stmt->execute([$categoryId, $recipeId]);
    }

    /**
     * @return int[]
     */
    public function recipeIds(int $categoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT recipe_id FROM category_recipe WHERE category_id = ?');
        $stmt->execute([$categoryId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return int[] category ids of $userId's own categories that already contain $recipeId
     */
    public function categoryIdsContaining(int $userId, int $recipeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.category_id FROM category_recipe cr
             INNER JOIN category c ON c.id = cr.category_id
             WHERE c.user_id = ? AND cr.recipe_id = ?'
        );
        $stmt->execute([$userId, $recipeId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function cast(array $row, bool $withCount = false): array
    {
        $category = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
        ];
        if ($withCount) {
            $category['recipe_count'] = (int) $row['recipe_count'];
        }

        return $category;
    }
}
