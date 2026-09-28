<?php

declare(strict_types=1);

namespace Kochbuch\Domain\PlaceholderImage;

use PDO;

/**
 * Admin-configured keyword->image mappings (todo.md "Placeholders for
 * Missing Images") - consumed by RecipeRepository to pick a placeholder for
 * a recipe with no uploaded image, and managed via PlaceholderImageController.
 */
final class PlaceholderImageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int,array{id:int,filename:string,is_default:bool,keywords:array<int,array{locale:string,keyword:string}>}>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM placeholder_image ORDER BY id')->fetchAll();
        $images = array_map(fn (array $row) => $this->cast($row, []), $rows);

        if ($images === []) {
            return [];
        }

        $keywordStmt = $this->pdo->query('SELECT * FROM placeholder_image_keyword ORDER BY id');
        $keywordsByImageId = [];
        foreach ($keywordStmt->fetchAll() as $keyword) {
            $keywordsByImageId[(int) $keyword['placeholder_image_id']][] = [
                'locale' => $keyword['locale'],
                'keyword' => $keyword['keyword'],
            ];
        }

        foreach ($images as &$image) {
            $image['keywords'] = $keywordsByImageId[$image['id']] ?? [];
        }

        return $images;
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $image) {
            if ($image['id'] === $id) {
                return $image;
            }
        }

        return null;
    }

    /**
     * @param array<int,array{locale:string,keyword:string}> $keywords
     */
    public function create(string $filename, bool $isDefault, array $keywords): int
    {
        $ownsTransaction = $this->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault();
            }

            $stmt = $this->pdo->prepare('INSERT INTO placeholder_image (filename, is_default, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$filename, $isDefault ? 1 : 0]);
            $id = (int) $this->pdo->lastInsertId();

            $this->replaceKeywords($id, $keywords);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<int,array{locale:string,keyword:string}> $keywords
     */
    public function update(int $id, ?string $filename, bool $isDefault, array $keywords): void
    {
        $ownsTransaction = $this->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearDefault();
            }

            if ($filename !== null) {
                $this->pdo->prepare('UPDATE placeholder_image SET filename = ?, is_default = ? WHERE id = ?')
                    ->execute([$filename, $isDefault ? 1 : 0, $id]);
            } else {
                $this->pdo->prepare('UPDATE placeholder_image SET is_default = ? WHERE id = ?')
                    ->execute([$isDefault ? 1 : 0, $id]);
            }

            $this->replaceKeywords($id, $keywords);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM placeholder_image WHERE id = ?')->execute([$id]);
    }

    private function clearDefault(): void
    {
        $this->pdo->exec('UPDATE placeholder_image SET is_default = 0');
    }

    /**
     * @param array<int,array{locale:string,keyword:string}> $keywords
     */
    private function replaceKeywords(int $placeholderImageId, array $keywords): void
    {
        $this->pdo->prepare('DELETE FROM placeholder_image_keyword WHERE placeholder_image_id = ?')->execute([$placeholderImageId]);

        $insert = $this->pdo->prepare('INSERT INTO placeholder_image_keyword (placeholder_image_id, locale, keyword) VALUES (?, ?, ?)');
        foreach ($keywords as $keyword) {
            if ($keyword['keyword'] === '') {
                continue;
            }
            $insert->execute([$placeholderImageId, $keyword['locale'], $keyword['keyword']]);
        }
    }

    /**
     * Same "own the commit/rollback only if no outer transaction is already
     * active" wrapper as RecipeRepository::beginTransaction() - required so
     * this also works nested inside a test's own outer transaction.
     */
    private function beginTransaction(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        $this->pdo->beginTransaction();

        return true;
    }

    /**
     * @param array<int,array{locale:string,keyword:string}> $keywords
     */
    private function cast(array $row, array $keywords): array
    {
        return [
            'id' => (int) $row['id'],
            'filename' => $row['filename'],
            'is_default' => (bool) $row['is_default'],
            'keywords' => $keywords,
        ];
    }
}
