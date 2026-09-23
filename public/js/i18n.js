/**
 * Client-side lookup for the same translations the home route resolved
 * server-side for the current locale (window.KOCHBUCH_TRANSLATIONS,
 * injected once per page load - see src/Service/Translator.php for the
 * PHP-side counterpart reading the same resources/i18n/{locale}.json
 * files). A missing key just returns itself, same as Translator::t()'s
 * last-resort behavior.
 */
function t(key, vars) {
    var strings = window.KOCHBUCH_TRANSLATIONS || {};
    var template = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;

    if (!vars) {
        return template;
    }

    return Object.keys(vars).reduce(function (result, name) {
        return result.split('{' + name + '}').join(String(vars[name]));
    }, template);
}

/**
 * Resolves a JSON API error body's {message, code} into a user-facing
 * string: prefers the machine-readable code translated via the
 * "error.<code>" key when the backend attached one and a translation
 * exists, falling back to the raw (English-only) message otherwise.
 */
function translateApiError(error) {
    if (!error) {
        return '';
    }
    if (!error.code) {
        return error.message;
    }
    var key = 'error.' + error.code;
    var translated = t(key);
    return translated === key ? error.message : translated;
}
