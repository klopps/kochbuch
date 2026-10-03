-- The "home_latest_recipes_count" setting (admin-configurable count for the
-- home page's curated "Latest Recipes" block) was removed once explicit
-- list sorting (todo.md "Sortierung der Rezeptliste") made that block
-- redundant - "sort by date, newest first" already covers the same need.
DELETE FROM setting WHERE `key` = 'home_latest_recipes_count';
