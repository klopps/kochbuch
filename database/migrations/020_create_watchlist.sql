-- todo.md "Watchlist": every user's own ordered list of recipes they'd like
-- to cook next (any recipe they can see - their own or others' public/
-- internal ones). position orders the list (new entries go to the end, the
-- list page reorders by drag handle). A recipe is on a user's list at most
-- once; deleting the user or the recipe removes the entry.
CREATE TABLE IF NOT EXISTS watchlist_entry (
    user_id INT UNSIGNED NOT NULL,
    recipe_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, recipe_id),
    KEY idx_watchlist_user_position (user_id, position),
    CONSTRAINT fk_watchlist_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT fk_watchlist_recipe FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
