CREATE TABLE IF NOT EXISTS user (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    preferred_locale VARCHAR(10) NOT NULL DEFAULT 'de',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_user_username (username),
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-use invite/reset tokens, same shape as YTAN's user_token table
-- (see CLAUDE.md: "Follow YTAN's ... both invite and password-reset funnel
-- through a single-use user_token table"). Not wired up to any endpoint
-- yet - the table exists so the auth flows can be built without a schema
-- change later.
CREATE TABLE IF NOT EXISTS user_token (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_user_token_token (token),
    KEY idx_user_token_user_id (user_id),
    CONSTRAINT fk_user_token_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
