-- todo.md "Last recipies configurable" - admin-configurable count for the
-- home page's "Latest Recipes" section (RecipeController::home()), same
-- pattern as recipe_page_sizes/recipe_default_page_size in 007.
INSERT INTO setting (`key`, `value`) VALUES
    ('home_latest_recipes_count', '6');
