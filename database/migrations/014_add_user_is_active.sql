-- todo.md "Deactivating users" - flat boolean column, same convention as
-- is_admin (no YTAN precedent for this one, see CLAUDE.md's "no YTAN
-- precedent, needs its own design" pattern). Defaults to active so existing
-- rows/new invites are unaffected.
ALTER TABLE user
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_admin;
