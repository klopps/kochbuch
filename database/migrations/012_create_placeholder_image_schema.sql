-- todo.md "Placeholders for Missing Images" - a recipe with no uploaded
-- image shows a placeholder chosen by matching keywords (in any configured
-- language) against the recipe's name, then its tags; if nothing matches,
-- the row with is_default = 1 is used. Files live under
-- public/storage/placeholder-images/ (plain static webroot files, not the
-- private storage/recipe-images/ pattern) since they're never
-- privacy-sensitive.
CREATE TABLE IF NOT EXISTS placeholder_image (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- locale is stored per keyword only so the admin UI can label which
-- language field a keyword came from - matching itself checks keywords of
-- every locale regardless of the current UI language, since a recipe's own
-- name/tags aren't tagged with a language.
CREATE TABLE IF NOT EXISTS placeholder_image_keyword (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    placeholder_image_id INT UNSIGNED NOT NULL,
    locale VARCHAR(5) NOT NULL,
    keyword VARCHAR(100) NOT NULL,
    KEY idx_placeholder_image_keyword_image_id (placeholder_image_id),
    CONSTRAINT fk_placeholder_image_keyword_image FOREIGN KEY (placeholder_image_id) REFERENCES placeholder_image (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
