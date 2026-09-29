<?php

declare(strict_types=1);

namespace Kochbuch\Domain\Recipe;

use PDO;
use Kochbuch\Domain\PlaceholderImage\PlaceholderImageRepository;
use Kochbuch\Service\PlaceholderImageMatcher;

/**
 * All recipe reads/writes, including the ingredient/step/tag child rows -
 * kept in one repository (rather than one per child table) since they're
 * never meaningfully used without their parent recipe and every write to
 * them happens inside the same request as a recipe create/update.
 */
final class RecipeRepository
{
    /**
     * Lazily-loaded, cached for the lifetime of this repository instance
     * (i.e. once per request) - see placeholders().
     */
    private ?array $placeholdersCache = null;

    /**
     * @param int[] $pageSizes allowed page sizes for search(); the default
     *              here only matters for call sites (bin/import-recipes.php,
     *              tests) that don't wire up the configurable
     *              SettingRepository value - see src/App.php.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlaceholderImageRepository $placeholderImages,
        private readonly array $pageSizes = [10, 20, 100],
    ) {
    }

    public function create(int $userId, array $data): int
    {
        $ownsTransaction = $this->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO recipe
                    (user_id, name, description, servings, difficulty, prep_time_minutes,
                     rest_time_minutes, cook_time_minutes, notes, calories, allergen_info,
                     is_vegan, is_vegetarian, is_pescetarian, source, source_url, visibility,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([
                $userId,
                $data['name'],
                $data['description'],
                $data['servings'],
                $data['difficulty'],
                $data['prep_time_minutes'],
                $data['rest_time_minutes'],
                $data['cook_time_minutes'],
                $data['notes'],
                $data['calories'],
                $data['allergen_info'],
                $data['is_vegan'] ? 1 : 0,
                $data['is_vegetarian'] ? 1 : 0,
                $data['is_pescetarian'] ? 1 : 0,
                $data['source'],
                $data['source_url'],
                $data['visibility'],
            ]);
            $recipeId = (int) $this->pdo->lastInsertId();

            $this->replaceIngredients($recipeId, $data['ingredients'] ?? []);
            $this->replaceSteps($recipeId, $data['steps'] ?? []);
            $this->replaceTags($recipeId, $data['tags'] ?? []);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $recipeId;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Starts a transaction unless one is already active (e.g. a test
     * wrapping the whole test method in its own outer transaction - PDO
     * has no real nested transactions, and calling beginTransaction() while
     * already in one throws). Returns whether THIS call is the one that
     * should commit/roll back, so an already-active outer transaction
     * always keeps ownership of the final commit/rollback decision.
     */
    private function beginTransaction(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        $this->pdo->beginTransaction();

        return true;
    }

    public function update(int $id, array $data): void
    {
        $ownsTransaction = $this->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE recipe SET
                    name = ?, description = ?, servings = ?, difficulty = ?, prep_time_minutes = ?,
                    rest_time_minutes = ?, cook_time_minutes = ?, notes = ?, calories = ?,
                    allergen_info = ?, is_vegan = ?, is_vegetarian = ?, is_pescetarian = ?,
                    source = ?, source_url = ?, visibility = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['name'],
                $data['description'],
                $data['servings'],
                $data['difficulty'],
                $data['prep_time_minutes'],
                $data['rest_time_minutes'],
                $data['cook_time_minutes'],
                $data['notes'],
                $data['calories'],
                $data['allergen_info'],
                $data['is_vegan'] ? 1 : 0,
                $data['is_vegetarian'] ? 1 : 0,
                $data['is_pescetarian'] ? 1 : 0,
                $data['source'],
                $data['source_url'],
                $data['visibility'],
                $id,
            ]);

            $this->replaceIngredients($id, $data['ingredients'] ?? []);
            $this->replaceSteps($id, $data['steps'] ?? []);
            $this->replaceTags($id, $data['tags'] ?? []);

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

    /**
     * Tags-only update (todo.md "Schnelle Tag-Zuordnung im Admin-Bereich") -
     * deliberately separate from update(), which requires the full recipe
     * body and would otherwise wipe ingredients/steps if a caller only sent
     * tags. Reuses the same replaceTags() the full update() path uses.
     *
     * @param string[] $tagNames
     */
    public function updateTags(int $recipeId, array $tagNames): void
    {
        $ownsTransaction = $this->beginTransaction();
        try {
            $this->replaceTags($recipeId, $tagNames);
            $this->pdo->prepare('UPDATE recipe SET updated_at = NOW() WHERE id = ?')->execute([$recipeId]);

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
        $stmt = $this->pdo->prepare('DELETE FROM recipe WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function find(int $id): ?array
    {
        // todo.md "Displaying the recipe author" - only the detail view
        // (this method) needs the author's username, so the LEFT JOIN
        // (LEFT, not INNER, in case the owning account was ever deleted)
        // stays scoped to here rather than every recipe-row query.
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.username AS owner_username FROM recipe r LEFT JOIN user u ON u.id = r.user_id WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $recipe = $stmt->fetch();
        if ($recipe === false) {
            return null;
        }

        return $this->hydrate($recipe);
    }

    /**
     * @param int[] $ids
     * @return array<int,array> keyed by recipe id, preserving only ids that exist
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM recipe WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = $this->summarize($row);
        }

        return $result;
    }

    /**
     * Mints a short-lived, unguessable token for the Bring! shopping-list
     * export (todo.md "Anbindung der Einkaufs-App Bring!") - mirrors
     * UserRepository::createToken()'s shape exactly, just scoped to a
     * recipe instead of a user. Opportunistically prunes already-expired
     * rows first so the table doesn't grow unbounded (no background job
     * exists in this project to do that separately).
     */
    public function createBringExportToken(int $recipeId, int $ttlSeconds): string
    {
        $this->pdo->prepare('DELETE FROM recipe_bring_export_token WHERE expires_at < NOW()')->execute();

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $this->pdo->prepare(
            'INSERT INTO recipe_bring_export_token (recipe_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$recipeId, $token, $expiresAt]);

        return $token;
    }

    /**
     * The recipe id for a not-yet-expired Bring! export token, or null -
     * unlike UserRepository's invite/reset tokens, this is deliberately
     * *not* single-use: Bring!'s own deeplink flow may fetch the URL more
     * than once within the token's short lifetime (once when the deeplink
     * is generated, possibly again when the app processes it), and there's
     * no reliable "import finished" signal to consume it on.
     */
    public function findRecipeIdForValidBringExportToken(string $token): ?int
    {
        $stmt = $this->pdo->prepare('SELECT recipe_id FROM recipe_bring_export_token WHERE token = ? AND expires_at > NOW()');
        $stmt->execute([$token]);
        $recipeId = $stmt->fetchColumn();

        return $recipeId === false ? null : (int) $recipeId;
    }

    /**
     * List/search - summary rows only (no ingredients/steps, those are only
     * needed on the detail page). Visibility (todo.md "Sichtbarkeitsstatus"):
     * public recipes are always included; internal ones once $currentUserId
     * is set (any logged-in user, not just the owner); private ones only
     * when $currentUserId owns them.
     *
     * `category_id` is ownership-checked against $currentUserId (a category
     * is personal - todo.md "Benutzeroberfläche und Suche" - so filtering by
     * one you don't own must never leak which of someone else's recipes are
     * in it). `uncategorized_mine` is the virtual "eigene Rezepte ohne
     * Kategorie" pseudo-category: $currentUserId's own recipes with no
     * category_recipe row at all; it takes priority over `category_id` if
     * both are somehow set.
     *
     * @param array{q?:string, difficulty?:string, vegan?:bool, vegetarian?:bool,
     *              pescetarian?:bool, untagged?:bool, mine_only?:bool, category_id?:int,
     *              uncategorized_mine?:bool, page?:int, per_page?:int} $filters
     * @param bool $bypassVisibility Admin-only escape hatch (todo.md "Schnelle
     *             Tag-Zuordnung im Admin-Bereich") - skips the visibility
     *             clause entirely so every recipe matches regardless of
     *             owner/visibility, matching AdminController::dashboardStats()'s
     *             "admin sees everything" convention. Never derived from user
     *             input - callers must gate this behind requireAdmin() themselves.
     * @return array{items: array[], total: int, page: int, per_page: int}
     */
    public function search(array $filters, ?int $currentUserId, bool $bypassVisibility = false): array
    {
        $where = [];
        $whereParams = [];
        $join = '';
        $joinParams = [];

        if ($bypassVisibility) {
            // No visibility clause at all.
        } elseif (!empty($filters['mine_only']) && $currentUserId !== null) {
            $where[] = 'r.user_id = ?';
            $whereParams[] = $currentUserId;
        } elseif ($currentUserId !== null) {
            $where[] = '(r.visibility IN ("public", "internal") OR r.user_id = ?)';
            $whereParams[] = $currentUserId;
        } else {
            $where[] = 'r.visibility = "public"';
        }

        if (!empty($filters['q'])) {
            // Tag match via EXISTS (not a JOIN) so a recipe with several
            // matching tags still contributes exactly one row.
            $where[] = '(r.name LIKE ? OR r.description LIKE ? OR EXISTS (
                SELECT 1 FROM recipe_tag rt2
                INNER JOIN tag t2 ON t2.id = rt2.tag_id
                WHERE rt2.recipe_id = r.id AND t2.name LIKE ?
            ))';
            $like = '%' . $filters['q'] . '%';
            $whereParams[] = $like;
            $whereParams[] = $like;
            $whereParams[] = $like;
        }
        if (!empty($filters['difficulty'])) {
            $where[] = 'r.difficulty = ?';
            $whereParams[] = $filters['difficulty'];
        }
        if (!empty($filters['vegan'])) {
            $where[] = 'r.is_vegan = 1';
        }
        if (!empty($filters['vegetarian'])) {
            $where[] = 'r.is_vegetarian = 1';
        }
        if (!empty($filters['pescetarian'])) {
            $where[] = 'r.is_pescetarian = 1';
        }
        if (!empty($filters['untagged'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM recipe_tag rt4 WHERE rt4.recipe_id = r.id)';
        }

        if (!empty($filters['uncategorized_mine']) && $currentUserId !== null) {
            $where[] = 'r.user_id = ? AND NOT EXISTS (SELECT 1 FROM category_recipe cr2 WHERE cr2.recipe_id = r.id)';
            $whereParams[] = $currentUserId;
        } elseif (!empty($filters['category_id']) && $currentUserId !== null) {
            $join = 'INNER JOIN category_recipe cr ON cr.recipe_id = r.id AND cr.category_id = ?
                     INNER JOIN category cat ON cat.id = cr.category_id AND cat.user_id = ?';
            $joinParams[] = $filters['category_id'];
            $joinParams[] = $currentUserId;
        }

        $params = array_merge($joinParams, $whereParams);
        // $where can be empty only when $bypassVisibility is true and no
        // other filter is set (an admin browsing with no filters at all).
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM recipe r $join WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = in_array($filters['per_page'] ?? null, $this->pageSizes, true) ? (int) $filters['per_page'] : $this->pageSizes[0];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        // $perPage/$offset are interpolated directly (not bound as ? params):
        // both are validated integers by construction above (whitelist /
        // max()), and Connection::fromEnv() disables emulated prepares,
        // which makes MySQL's native prepared statements reject a
        // non-constant-folded LIMIT/OFFSET placeholder in some driver
        // configurations - safe here since neither value is user-supplied text.
        $sql = "SELECT r.* FROM recipe r $join WHERE $whereSql ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => array_map(fn (array $row) => $this->summarize($row), $stmt->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Home page feed (todo.md "Anzeige der Rezepte auf Startseite"): a
     * curated "Latest Recipes" block (the newest private/internal recipes
     * visible to $currentUserId - empty for a logged-out visitor, who can
     * never see private/internal recipes at all, see BaseController's
     * visibility rules) followed by "Random Recipes" (everything else this
     * viewer can see, in a stable-but-shuffled order, paginated). Without
     * this split, the many bulk-imported public recipes would bury a
     * user's own recent private/internal ones in a plain "newest first"
     * list.
     *
     * $randomSeed drives `ORDER BY RAND(seed)`, which - unlike bare
     * `RAND()` - is deterministic for a given seed, so paging through
     * "Random Recipes" with the same seed never repeats/skips a row
     * (barring concurrent inserts/deletes) - see RecipeController::home(),
     * which mints a seed once and has the frontend persist it in the URL.
     *
     * @return array{latest: array[], random: array{items: array[], total: int, page: int, per_page: int}}
     */
    public function homeFeed(?int $currentUserId, int $latestLimit, int $randomPage, int $randomPerPage, int $randomSeed): array
    {
        $latestRows = [];
        if ($currentUserId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM recipe WHERE visibility = 'internal' OR (visibility = 'private' AND user_id = ?)
                 ORDER BY created_at DESC LIMIT $latestLimit"
            );
            $stmt->execute([$currentUserId]);
            $latestRows = $stmt->fetchAll();
        }
        $latestIds = array_map(fn (array $row) => (int) $row['id'], $latestRows);

        // Same visibility rule as search() (public+internal+own, or just
        // public for a guest), plus excluding whatever "Latest Recipes"
        // already shows so nothing appears twice.
        if ($currentUserId !== null) {
            $where = '(visibility IN ("public", "internal") OR user_id = ?)';
            $whereParams = [$currentUserId];
        } else {
            $where = 'visibility = "public"';
            $whereParams = [];
        }
        if ($latestIds !== []) {
            $placeholders = implode(',', array_fill(0, count($latestIds), '?'));
            $where .= " AND id NOT IN ($placeholders)";
            $whereParams = array_merge($whereParams, $latestIds);
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM recipe WHERE $where");
        $countStmt->execute($whereParams);
        $randomTotal = (int) $countStmt->fetchColumn();

        $perPage = in_array($randomPerPage, $this->pageSizes, true) ? $randomPerPage : $this->pageSizes[0];
        $page = max(1, $randomPage);
        $offset = ($page - 1) * $perPage;

        // $perPage/$offset interpolated directly, same reasoning as
        // search(): both are validated integers, never user-supplied text.
        // $randomSeed is bound as a param (not interpolated) since RAND()
        // accepts a normal numeric argument like any other function call.
        $sql = "SELECT * FROM recipe WHERE $where ORDER BY RAND(?) LIMIT $perPage OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$whereParams, $randomSeed]);

        return [
            'latest' => array_map(fn (array $row) => $this->summarize($row), $latestRows),
            'random' => [
                'items' => array_map(fn (array $row) => $this->summarize($row), $stmt->fetchAll()),
                'total' => $randomTotal,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];
    }

    private function replaceIngredients(int $recipeId, array $ingredients): void
    {
        $this->pdo->prepare('DELETE FROM recipe_ingredient WHERE recipe_id = ?')->execute([$recipeId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO recipe_ingredient (recipe_id, position, is_heading, name, amount, unit, note) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($ingredients) as $position => $ingredient) {
            if (trim((string) ($ingredient['name'] ?? '')) === '') {
                continue;
            }
            $isHeading = !empty($ingredient['is_heading']);
            $amount = $ingredient['amount'] ?? null;
            $stmt->execute([
                $recipeId,
                $position,
                $isHeading ? 1 : 0,
                $ingredient['name'],
                // A heading has no amount/unit/note, regardless of what a
                // client sent along with it - the fields are meaningless
                // for it and would otherwise round-trip through the API.
                (!$isHeading && $amount !== null && $amount !== '') ? (float) $amount : null,
                $isHeading ? null : (($ingredient['unit'] ?? null) ?: null),
                $isHeading ? null : (($ingredient['note'] ?? null) ?: null),
            ]);
        }
    }

    /**
     * @param array<int, array{instruction?: mixed, is_heading?: mixed}> $steps
     */
    private function replaceSteps(int $recipeId, array $steps): void
    {
        $this->pdo->prepare('DELETE FROM recipe_step WHERE recipe_id = ?')->execute([$recipeId]);

        $stmt = $this->pdo->prepare('INSERT INTO recipe_step (recipe_id, position, is_heading, instruction) VALUES (?, ?, ?, ?)');
        $position = 0;
        foreach ($steps as $step) {
            $instruction = trim((string) ($step['instruction'] ?? ''));
            if ($instruction === '') {
                continue;
            }
            $stmt->execute([$recipeId, $position, !empty($step['is_heading']) ? 1 : 0, $instruction]);
            $position++;
        }
    }

    /**
     * @param string[] $tagNames
     */
    private function replaceTags(int $recipeId, array $tagNames): void
    {
        $this->pdo->prepare('DELETE FROM recipe_tag WHERE recipe_id = ?')->execute([$recipeId]);

        $insertTag = $this->pdo->prepare('INSERT IGNORE INTO tag (name) VALUES (?)');
        $findTag = $this->pdo->prepare('SELECT id FROM tag WHERE name = ?');
        $link = $this->pdo->prepare('INSERT IGNORE INTO recipe_tag (recipe_id, tag_id) VALUES (?, ?)');

        foreach (array_unique(array_filter(array_map('trim', $tagNames))) as $name) {
            $insertTag->execute([$name]);
            $findTag->execute([$name]);
            $tagId = $findTag->fetchColumn();
            if ($tagId !== false) {
                $link->execute([$recipeId, (int) $tagId]);
            }
        }
    }

    public function addImage(int $recipeId, string $filename): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO recipe_image (recipe_id, filename, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$recipeId, $filename]);
        $imageId = (int) $this->pdo->lastInsertId();

        // First image on a recipe automatically becomes the primary one -
        // otherwise a freshly-imaged recipe would show no cover photo at
        // all until the user explicitly picks one.
        $stmt = $this->pdo->prepare('SELECT primary_image_id FROM recipe WHERE id = ?');
        $stmt->execute([$recipeId]);
        if ($stmt->fetchColumn() === null) {
            $this->setPrimaryImage($recipeId, $imageId);
        }

        return $imageId;
    }

    public function findImage(int $recipeId, int $imageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recipe_image WHERE id = ? AND recipe_id = ?');
        $stmt->execute([$imageId, $recipeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function removeImage(int $recipeId, int $imageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM recipe_image WHERE id = ? AND recipe_id = ?');
        $stmt->execute([$imageId, $recipeId]);

        // primary_image_id's FK is ON DELETE SET NULL, so deleting the
        // current default image clears it automatically - but leaving it
        // null while other images still exist would silently fall back to
        // the placeholder image instead of one of them. Promote the next
        // remaining image (lowest id, same order the gallery already lists
        // them in) as the new default, mirroring addImage()'s "first image
        // automatically becomes the default" behavior.
        $stmt = $this->pdo->prepare('SELECT primary_image_id FROM recipe WHERE id = ?');
        $stmt->execute([$recipeId]);
        if ($stmt->fetchColumn() !== null) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM recipe_image WHERE recipe_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$recipeId]);
        $nextImageId = $stmt->fetchColumn();
        if ($nextImageId !== false) {
            $this->setPrimaryImage($recipeId, (int) $nextImageId);
        }
    }

    public function setPrimaryImage(int $recipeId, ?int $imageId): void
    {
        $stmt = $this->pdo->prepare('UPDATE recipe SET primary_image_id = ? WHERE id = ?');
        $stmt->execute([$imageId, $recipeId]);
    }

    private function summarize(array $row): array
    {
        $recipe = $this->castRow($row);
        $recipe['tags'] = $this->tagsFor((int) $row['id']);
        $recipe['placeholder_image_filename'] = $this->placeholderImageFilenameFor($recipe);

        return $recipe;
    }

    private function hydrate(array $row): array
    {
        $recipe = $this->castRow($row);
        $recipeId = (int) $row['id'];

        $stmt = $this->pdo->prepare('SELECT is_heading, name, amount, unit, note FROM recipe_ingredient WHERE recipe_id = ? ORDER BY position');
        $stmt->execute([$recipeId]);
        $recipe['ingredients'] = array_map(static function (array $i) {
            $i['is_heading'] = (bool) $i['is_heading'];
            $i['amount'] = $i['amount'] !== null ? (float) $i['amount'] : null;

            return $i;
        }, $stmt->fetchAll());

        $stmt = $this->pdo->prepare('SELECT is_heading, instruction FROM recipe_step WHERE recipe_id = ? ORDER BY position');
        $stmt->execute([$recipeId]);
        $recipe['steps'] = array_map(static function (array $s) {
            $s['is_heading'] = (bool) $s['is_heading'];

            return $s;
        }, $stmt->fetchAll());

        $recipe['tags'] = $this->tagsFor($recipeId);

        $stmt = $this->pdo->prepare('SELECT id FROM recipe_image WHERE recipe_id = ? ORDER BY id');
        $stmt->execute([$recipeId]);
        $recipe['images'] = array_map(static fn ($id) => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
        $recipe['placeholder_image_filename'] = $this->placeholderImageFilenameFor($recipe);

        return $recipe;
    }

    /**
     * todo.md "Placeholders for Missing Images" - only relevant once a
     * recipe has no uploaded image of its own; null otherwise. Matches the
     * recipe's name, then its tags, against the admin-configured keyword
     * list (PlaceholderImageMatcher), falling back to whichever placeholder
     * is marked as the default - or null if neither a match nor a default
     * exists yet (a fresh install before an admin has configured anything),
     * in which case the frontend keeps its own icon-based fallback.
     */
    private function placeholderImageFilenameFor(array $recipe): ?string
    {
        if ($recipe['primary_image_id'] !== null) {
            return null;
        }

        $placeholders = $this->placeholders();
        $filename = PlaceholderImageMatcher::match($recipe['name'], $recipe['tags'], $placeholders);
        if ($filename !== null) {
            return $filename;
        }

        foreach ($placeholders as $placeholder) {
            if ($placeholder['is_default']) {
                return $placeholder['filename'];
            }
        }

        return null;
    }

    /**
     * @return array<int,array{id:int,filename:string,is_default:bool,keywords:array<int,array{locale:string,keyword:string}>}>
     */
    private function placeholders(): array
    {
        return $this->placeholdersCache ??= $this->placeholderImages->all();
    }

    private function castRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            // Only present when the query joined it in (find(), see its own
            // doc-comment) - null everywhere else, which is fine since
            // nothing reads it there.
            'owner_username' => $row['owner_username'] ?? null,
            'name' => $row['name'],
            'description' => $row['description'],
            'servings' => (int) $row['servings'],
            'difficulty' => $row['difficulty'],
            'prep_time_minutes' => $row['prep_time_minutes'] !== null ? (int) $row['prep_time_minutes'] : null,
            'rest_time_minutes' => $row['rest_time_minutes'] !== null ? (int) $row['rest_time_minutes'] : null,
            'cook_time_minutes' => $row['cook_time_minutes'] !== null ? (int) $row['cook_time_minutes'] : null,
            'notes' => $row['notes'],
            'calories' => $row['calories'] !== null ? (int) $row['calories'] : null,
            'allergen_info' => $row['allergen_info'],
            'is_vegan' => (bool) $row['is_vegan'],
            'is_vegetarian' => (bool) $row['is_vegetarian'],
            'is_pescetarian' => (bool) $row['is_pescetarian'],
            'source' => $row['source'],
            'source_url' => $row['source_url'],
            'visibility' => $row['visibility'],
            'primary_image_id' => $row['primary_image_id'] !== null ? (int) $row['primary_image_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function tagsFor(int $recipeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.name FROM tag t INNER JOIN recipe_tag rt ON rt.tag_id = t.id WHERE rt.recipe_id = ? ORDER BY t.name'
        );
        $stmt->execute([$recipeId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
