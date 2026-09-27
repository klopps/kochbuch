-- The "translate_tool_enabled" toggle (originally gating whether the
-- /admin/translate nav item/routes existed at all) was removed per
-- todo.md's "Einstellungen überarbeiten" - the translate tool is now
-- always available to admins, same as every other admin page.
DELETE FROM setting WHERE `key` = 'translate_tool_enabled';
