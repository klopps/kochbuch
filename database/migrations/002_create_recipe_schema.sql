CREATE TABLE IF NOT EXISTS recipe (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    servings SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    difficulty VARCHAR(20) NOT NULL DEFAULT 'normal',
    prep_time_minutes SMALLINT UNSIGNED NULL,
    rest_time_minutes SMALLINT UNSIGNED NULL,
    cook_time_minutes SMALLINT UNSIGNED NULL,
    notes TEXT NULL,
    calories SMALLINT UNSIGNED NULL,
    allergen_info TEXT NULL,
    is_vegan TINYINT(1) NOT NULL DEFAULT 0,
    is_vegetarian TINYINT(1) NOT NULL DEFAULT 0,
    is_pescetarian TINYINT(1) NOT NULL DEFAULT 0,
    source VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'private',
    primary_image_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_recipe_user_id (user_id),
    KEY idx_recipe_visibility (visibility),
    CONSTRAINT fk_recipe_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_ingredient (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(200) NOT NULL,
    amount DECIMAL(10,3) NULL,
    unit VARCHAR(30) NULL,
    note VARCHAR(255) NULL,
    KEY idx_recipe_ingredient_recipe_id (recipe_id),
    CONSTRAINT fk_recipe_ingredient_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_step (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    instruction TEXT NOT NULL,
    KEY idx_recipe_step_recipe_id (recipe_id),
    CONSTRAINT fk_recipe_step_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outside the public webroot (see storage/recipe-images/), same reasoning
-- as YTAN's tour/poi/route/area images: a private recipe's images must not
-- be reachable via a static, guessable URL - GET /api/v1/recipes/{id}/images/{imageId}
-- re-checks the recipe's own visibility on every request instead.
CREATE TABLE IF NOT EXISTS recipe_image (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_recipe_image_recipe_id (recipe_id),
    CONSTRAINT fk_recipe_image_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE recipe
    ADD CONSTRAINT fk_recipe_primary_image FOREIGN KEY (primary_image_id) REFERENCES recipe_image (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS tag (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_tag_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_tag (
    recipe_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, tag_id),
    CONSTRAINT fk_recipe_tag_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_tag_tag FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Jeder Nutzer kann eigene Kategorien definieren und beliebige Rezepte den
-- Kategorien zuordnen" (todo.md) - a category belongs to one user, but the
-- recipes assigned to it can be anyone's (own or public), so category_recipe
-- deliberately has no FK-cascading relationship to the recipe's own owner.
CREATE TABLE IF NOT EXISTS category (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_category_user_id (user_id),
    CONSTRAINT fk_category_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS category_recipe (
    category_id INT UNSIGNED NOT NULL,
    recipe_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (category_id, recipe_id),
    CONSTRAINT fk_category_recipe_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE,
    CONSTRAINT fk_category_recipe_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
