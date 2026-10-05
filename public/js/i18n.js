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
    var translated = t(key, apiErrorVars(error));
    return translated === key ? error.message : translated;
}

/**
 * Placeholders a translated error message may use, from extra fields the
 * backend attached (ApiException details) - e.g. a Gemini rate limit's
 * retry_at becomes {time} (local wall-clock time) and {seconds}.
 */
function apiErrorVars(error) {
    var vars = {};
    if (error.retry_at) {
        var at = new Date(error.retry_at);
        vars.time = at.toLocaleTimeString(window.KOCHBUCH_LOCALE, { hour: '2-digit', minute: '2-digit' });
        vars.seconds = Math.max(1, Math.round((at.getTime() - Date.now()) / 1000));
    }
    if (error.retry_in_seconds && vars.seconds === undefined) {
        vars.seconds = error.retry_in_seconds;
    }

    return vars;
}
