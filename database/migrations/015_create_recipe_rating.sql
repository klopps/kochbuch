-- Rezept-Bewertungssystem: jeder angemeldete Benutzer kann jedes für ihn
-- sichtbare Rezept auf einer Skala von 1-5 Sternen bewerten. Kein
-- bestehendes "eine Zeile pro Benutzer+Rezept"-Muster im Projekt
-- (recipe_tag/category_recipe sind reine m:n-Tabellen ohne Besitzer-Spalte)
-- - der zusammengesetzte Primärschlüssel aus beiden Fremdschlüsseln
-- garantiert genau eine Zeile pro Benutzer+Rezept, sodass ein erneutes
-- Bewerten ein reiner Upsert ist (RecipeRepository::rate()).
CREATE TABLE IF NOT EXISTS recipe_rating (
    recipe_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (recipe_id, user_id),
    CONSTRAINT fk_recipe_rating_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_rating_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT chk_recipe_rating_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
