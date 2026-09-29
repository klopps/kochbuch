-- todo.md "Changing your own user details" - firstname/lastname for the new
-- self-service Profile screen (mirrors YTAN's user.firstname/lastname).
ALTER TABLE user
    ADD COLUMN firstname VARCHAR(100) NULL AFTER username,
    ADD COLUMN lastname VARCHAR(100) NULL AFTER firstname;

-- Carries the pending new email address for a 'email_change' user_token row
-- (mirrors YTAN's user_token.payload) - an email change only takes effect
-- once the confirmation link mailed to the new address is redeemed
-- (AuthService::confirmEmailChange()), so the not-yet-confirmed address has
-- to live somewhere until then.
ALTER TABLE user_token
    ADD COLUMN payload VARCHAR(255) NULL AFTER purpose;
