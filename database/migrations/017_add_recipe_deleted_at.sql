-- todo.md "Deleting Recipes" - deleting a recipe must not remove it from the
-- database, only mark it deleted; the admin area can then restore it or
-- permanently delete it (RecipeController::delete()/adminRestore()/
-- adminPermanentlyDelete()).
ALTER TABLE recipe ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at;
ALTER TABLE recipe ADD KEY idx_recipe_deleted_at (deleted_at);
