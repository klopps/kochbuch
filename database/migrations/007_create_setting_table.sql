-- Flat key/value application settings, editable at runtime via the admin
-- "Einstellungen" page (todo.md "Admin-Oberfläche") without touching .env or
-- redeploying - see src/Domain/Setting/SettingRepository.php.
CREATE TABLE IF NOT EXISTS setting (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO setting (`key`, `value`) VALUES
    ('app_name', 'Kochbuch'),
    ('default_locale', 'de'),
    ('translate_tool_enabled', '0'),
    ('recipe_page_sizes', '10,20,100'),
    ('recipe_default_page_size', '10');
