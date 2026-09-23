/**
 * Theme (light/dark) + language persistence, read back server-side by
 * Translator::resolveLocale() via the same "settings" cookie so the first
 * response is already in the right language/theme - see CLAUDE.md.
 */
const SETTINGS_COOKIE = 'settings';

function loadSettings() {
    const match = document.cookie.match(new RegExp('(?:^|; )' + SETTINGS_COOKIE + '=([^;]*)'));
    if (!match) {
        return { theme: 'light', language: window.KOCHBUCH_LOCALE || 'de' };
    }
    try {
        return JSON.parse(decodeURIComponent(match[1]));
    } catch (e) {
        return { theme: 'light', language: window.KOCHBUCH_LOCALE || 'de' };
    }
}

function saveSettings(settings) {
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = SETTINGS_COOKIE + '=' + encodeURIComponent(JSON.stringify(settings)) + '; path=/; max-age=' + oneYear;
}

// Sets both our own [data-theme] (public/css/style.css's tokens) and
// Bootstrap 5.3's own [data-bs-theme] (its built-in dark-mode component
// styles) together, so custom and Bootstrap-rendered UI stay in sync.
function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    document.documentElement.setAttribute('data-bs-theme', theme);
}

function setTheme(theme) {
    const settings = loadSettings();
    settings.theme = theme;
    saveSettings(settings);
    applyTheme(theme);
}

// Called as the very first thing in templates/app.php's inline bootstrap
// script, so a returning dark-mode user never sees a flash of the light
// theme.
function applyStoredTheme() {
    applyTheme(loadSettings().theme || 'light');
}

/**
 * Unlike the theme (pure client-side CSS attribute), the active language
 * is resolved server-side (Translator::resolveLocale() reads this same
 * cookie - see CLAUDE.md) and baked into the page as window.KOCHBUCH_TRANSLATIONS,
 * so switching it needs a full reload rather than an in-place DOM update.
 */
function setLanguage(language) {
    const settings = loadSettings();
    settings.language = language;
    saveSettings(settings);
    location.reload();
}
