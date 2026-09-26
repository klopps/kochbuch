-- Admin-configurable defaults for the new "Schrift- und Buttongrößen"
-- feature (todo.md) - the percentage a first-time visitor's browser (no
-- "settings" cookie yet for this preference) starts at, separately for
-- desktop and mobile. Every existing/new user starts at 100%/80% per
-- todo.md's explicit rollout instruction.
INSERT INTO setting (`key`, `value`) VALUES
    ('default_font_scale_desktop', '100'),
    ('default_font_scale_mobile', '80');
