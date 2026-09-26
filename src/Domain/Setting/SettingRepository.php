<?php

declare(strict_types=1);

namespace Kochbuch\Domain\Setting;

use PDO;
use Throwable;

/**
 * Flat key/value application settings (app name, default locale, whether
 * the translation tool is active, recipe list page-size options), editable
 * at runtime via the admin "Einstellungen" page (todo.md "Admin-
 * Oberfläche") without touching .env or redeploying - unlike the
 * connection/secret settings in .env, these are meant to change while the
 * app keeps running. Seeded with defaults by
 * database/migrations/007_create_setting_table.sql.
 */
final class SettingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->pdo->query('SELECT `key`, `value` FROM setting')->fetchAll() as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM setting WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : in_array($value, ['1', 'true'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    /**
     * Parses a comma-separated list of positive integers (e.g. recipe list
     * page-size options), e.g. "10,20,100" -> [10, 20, 100].
     *
     * @param int[] $default
     * @return int[]
     */
    public function getIntList(string $key, array $default = []): array
    {
        $value = $this->get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $numbers = array_map('intval', explode(',', $value));

        return array_values(array_filter($numbers, fn (int $n) => $n > 0));
    }

    /**
     * Upserts one or more key/value pairs in a single transaction - the
     * admin settings form always replaces the full set together.
     * beginTransaction()/commit() are skipped when a transaction is already
     * active (PHPUnit's TestCase wraps every test in its own outer one,
     * same reasoning as RecipeRepository's beginTransaction() wrapper - see
     * CLAUDE.md's testing notes).
     *
     * @param array<string, string> $pairs
     */
    public function setMany(array $pairs): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO setting (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            foreach ($pairs as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
