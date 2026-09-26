/**
 * Theme (light/dark) + language persistence, read back server-side by
 * Translator::resolveLocale() via the same "settings" cookie so the first
 * response is already in the right language/theme - see CLAUDE.md.
 */
const SETTINGS_COOKIE = 'settings';

/**
 * Admin-configurable starting point (todo.md "Admin-Oberfläche" ->
 * Einstellungen) for a browser that has no "settings" cookie yet - see
 * window.KOCHBUCH_SETTINGS, injected by templates/app.php from
 * SettingRepository. Falls back to todo.md's own rollout defaults (100%
 * desktop / 80% mobile) when that global is missing entirely (e.g. on the
 * legal/set-password standalone pages, which don't inject it).
 */
function defaultFontScale(which) {
    const settings = window.KOCHBUCH_SETTINGS || {};

    return which === 'desktop'
        ? (settings.default_font_scale_desktop || 100)
        : (settings.default_font_scale_mobile || 80);
}

function loadSettings() {
    const defaults = {
        theme: 'light',
        language: window.KOCHBUCH_LOCALE || 'de',
        fontScaleDesktop: defaultFontScale('desktop'),
        fontScaleMobile: defaultFontScale('mobile'),
    };

    const match = document.cookie.match(new RegExp('(?:^|; )' + SETTINGS_COOKIE + '=([^;]*)'));
    if (!match) {
        return defaults;
    }
    try {
        const parsed = JSON.parse(decodeURIComponent(match[1]));

        // Backfills a cookie saved before this feature existed - without
        // this, an existing visitor's font scale would read as
        // undefined/NaN instead of falling back to the rollout default.
        return Object.assign({}, defaults, parsed);
    } catch (e) {
        return defaults;
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

/**
 * Sets the two --font-scale-* custom properties style.css's `html { }`
 * rule reads (todo.md "Anpassungen von Schrift- und Buttongrößen") -
 * plain CSS percentages of the browser's own default size, so "100%"
 * really does mean a normal 1rem/16px root size and "80%" 0.8rem/12.8px,
 * matching todo.md's own worked example exactly. Bootstrap's own
 * components (buttons, form controls, spacing) are sized in rem relative
 * to this root size, so this one change scales fonts AND buttons together.
 */
function applyFontScale(desktopPercent, mobilePercent) {
    document.documentElement.style.setProperty('--font-scale-desktop', desktopPercent + '%');
    document.documentElement.style.setProperty('--font-scale-mobile', mobilePercent + '%');
}

function setFontScale(which, percent) {
    const clamped = Math.min(150, Math.max(50, Math.round(percent)));
    const settings = loadSettings();
    if (which === 'desktop') {
        settings.fontScaleDesktop = clamped;
    } else {
        settings.fontScaleMobile = clamped;
    }
    saveSettings(settings);
    applyFontScale(settings.fontScaleDesktop, settings.fontScaleMobile);

    return clamped;
}

// Called as the very first thing in templates/app.php's (and every
// standalone page's) inline bootstrap script, so a returning dark-mode/
// custom-font-scale user never sees a flash of the light theme or the
// wrong text size.
function applyStoredTheme() {
    const settings = loadSettings();
    applyTheme(settings.theme || 'light');
    applyFontScale(settings.fontScaleDesktop, settings.fontScaleMobile);
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
