-- Enables single-use consumption of invite/reset tokens (findValidToken()/
-- consumeToken() in UserRepository, mirroring YTAN's user_token semantics -
-- see CLAUDE.md's planned user management section): NULL = not yet
-- redeemed, set to NOW() the moment a token is used so it can never be
-- replayed.
ALTER TABLE user_token ADD COLUMN used_at DATETIME NULL AFTER expires_at;
