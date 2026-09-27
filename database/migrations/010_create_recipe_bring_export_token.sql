-- Short-lived, unguessable "capability" tokens backing the Bring!
-- shopping-list export (todo.md "Anbindung der Einkaufs-App Bring!") - same
-- shape/purpose as user_token, but scoped to a recipe instead of a user.
-- Bring!'s server fetches the recipe unauthenticated via this token, so the
-- normal Kochbuch visibility check only ever runs once, at token-creation
-- time (RecipeController::bringExport(), gated like show()) - possession of
-- the token is the only check afterwards, same as an invite/reset link.
CREATE TABLE IF NOT EXISTS recipe_bring_export_token (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_recipe_bring_export_token_token (token),
    KEY idx_recipe_bring_export_token_recipe_id (recipe_id),
    CONSTRAINT fk_recipe_bring_export_token_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
