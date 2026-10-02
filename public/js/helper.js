/**
 * Small stateless helpers shared across public/js/views/*.js.
 */

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);

    return div.innerHTML;
}

/**
 * todo.md "Loading Indicator During Longer Processes": wraps a "Save"-style
 * (or any other long-running) button action so the button can never be
 * double-clicked mid-request, always shows a spinner while the action is in
 * flight, and always surfaces a failure - including api-client.js's own
 * request timeout, which arrives as a normal error here, no separate code
 * path needed - in a blocking error modal (error-dialog.js) rather than
 * this app's usual toast, since this is specifically about an action the
 * user is actively waiting on. `action` is a function, not an
 * already-started promise, so the button is disabled before the request
 * even begins, closing the double-submit window the todo item describes.
 *
 * A successful action typically navigates away or re-renders the view the
 * button lives in, making the `finally` restore moot (harmless either way)
 * - callers don't need to do anything differently for success vs. the
 * button simply disappearing.
 */
async function withBusyButton(button, action) {
    if (button.disabled) {
        return;
    }
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

    try {
        await action();
    } catch (err) {
        await showErrorDialog(translateApiError(err.data) || err.message);
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

/**
 * Rounds to 2 decimals and trims trailing zeros (400 stays "400", 133.333
 * becomes "133.33", 1 stays "1") - used for portion-scaled ingredient
 * amounts (see views/recipe-detail.js).
 */
function formatAmount(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const rounded = Math.round(value * 100) / 100;

    return String(rounded);
}

const DIFFICULTY_LABEL_KEY = {
    easy: 'difficulty.easy',
    normal: 'difficulty.normal',
    hard: 'difficulty.hard',
    challenging: 'difficulty.challenging',
};

function difficultyBadgeHtml(difficulty) {
    if (!difficulty) {
        return '';
    }

    return '<span class="badge badge-difficulty-' + difficulty + '">' + escapeHtml(t(DIFFICULTY_LABEL_KEY[difficulty] || difficulty)) + '</span>';
}

/**
 * Three visibility levels (todo.md "Sichtbarkeitsstatus"): private (lock,
 * owner/admin only), internal (people icon, any logged-in user - the
 * default for new recipes), public (globe, no icon shown on compact cards
 * to keep the common case uncluttered - see recipeCardHtml()).
 */
const VISIBILITY_META = {
    private: { icon: 'bi-lock', labelKey: 'recipe.private' },
    internal: { icon: 'bi-people', labelKey: 'recipe.internal' },
    public: { icon: 'bi-globe', labelKey: 'recipe.public' },
};

function dietBadgesHtml(recipe) {
    const badges = [];
    if (recipe.is_vegan) {
        badges.push('<span class="badge text-bg-success">' + escapeHtml(t('diet.vegan')) + '</span>');
    } else if (recipe.is_vegetarian) {
        badges.push('<span class="badge text-bg-success">' + escapeHtml(t('diet.vegetarian')) + '</span>');
    }
    if (recipe.is_pescetarian) {
        badges.push('<span class="badge text-bg-info">' + escapeHtml(t('diet.pescetarian')) + '</span>');
    }

    return badges.join(' ');
}

/**
 * Read-only 1-5 star display for a recipe's average_rating/rating_count
 * (as opposed to the clickable rating picker in views/recipe-detail.js) -
 * '' once there's no rating yet, same early-return convention as
 * difficultyBadgeHtml()/dietBadgesHtml(). Rounds to the nearest 0.5 so a
 * value like 3.3 renders as 3 full stars + 1 half star (3.3 is closer to
 * 3.5 than to 3.0), using Bootstrap Icons' own half-star glyph rather than
 * a CSS partial-fill trick.
 *
 * todo.md "Displaying Star Ratings": the exact average (one decimal place)
 * and the rating count follow the stars as plain text, e.g. "4.7 (5)" -
 * since this is shared by recipeCardHtml() and the detail view, it appears
 * wherever the stars themselves do rather than needing a separate line.
 */
function starRatingHtml(averageRating, ratingCount) {
    if (averageRating === null || averageRating === undefined || !ratingCount) {
        return '';
    }
    const rounded = Math.round(averageRating * 2) / 2;
    let icons = '';
    for (let i = 1; i <= 5; i++) {
        if (rounded >= i) {
            icons += '<i class="bi bi-star-fill recipe-rating-star is-filled"></i>';
        } else if (rounded >= i - 0.5) {
            icons += '<i class="bi bi-star-half recipe-rating-star is-filled"></i>';
        } else {
            icons += '<i class="bi bi-star recipe-rating-star"></i>';
        }
    }

    return (
        '<span class="recipe-rating">' + icons +
        ' <span class="recipe-rating-value">' + escapeHtml(averageRating.toFixed(1)) + ' (' + ratingCount + ')</span>' +
        '</span>'
    );
}

function totalTimeMinutes(recipe) {
    return (recipe.prep_time_minutes || 0) + (recipe.rest_time_minutes || 0) + (recipe.cook_time_minutes || 0);
}

function timeLabel(minutes) {
    if (!minutes) {
        return null;
    }
    if (minutes < 60) {
        return minutes + ' min';
    }
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest ? hours + ' h ' + rest + ' min' : hours + ' h';
}

/**
 * API path (not a directly loadable URL) for a recipe's primary image -
 * see hydrateAuthImages() for why every recipe <img> is loaded via an
 * authenticated fetch() instead of a plain src=.
 */
function recipeImagePath(recipe) {
    if (!recipe.primary_image_id) {
        return null;
    }

    return '/recipes/' + recipe.id + '/images/' + recipe.primary_image_id;
}

/**
 * The app's own site root (as opposed to Kochbuch.get()'s API root, which
 * has "/api/v1" appended) - subdirectory-safe the same way window.
 * KOCHBUCH_API_BASE itself is, since it's just that value with the API
 * suffix stripped back off.
 */
function siteBaseUrl() {
    return (window.KOCHBUCH_API_BASE || '/api/v1').replace(/\/api\/v1$/, '');
}

/**
 * todo.md "Placeholders for Missing Images" - a plain static file under
 * public/storage/placeholder-images/, unlike a real recipe photo's
 * authenticated per-request path, so it's safe to use directly as an
 * <img src=...>.
 */
function placeholderImageUrl(filename) {
    return siteBaseUrl() + '/storage/placeholder-images/' + encodeURIComponent(filename);
}

function recipeImageHtml(recipe) {
    const imagePath = recipeImagePath(recipe);
    if (imagePath) {
        return '<img data-recipe-image="' + escapeHtml(imagePath) + '" alt="">';
    }
    if (recipe.placeholder_image_filename) {
        // todo.md "Image and Placeholder Scaling" - a placeholder is never
        // stretched past its own resolution the way a real photo is (see
        // .is-placeholder in style.css), so a small generic icon-like image
        // doesn't get blown up into a blurry mess filling the card.
        return '<img class="is-placeholder" src="' + escapeHtml(placeholderImageUrl(recipe.placeholder_image_filename)) + '" alt="">';
    }

    return '<i class="bi bi-egg-fried"></i>';
}

function recipeCardHtml(recipe) {
    const time = timeLabel(totalTimeMinutes(recipe));
    const tags = (recipe.tags || []).slice(0, 3)
        .map((tag) => '<span class="tag-chip">' + escapeHtml(tag) + '</span>')
        .join(' ');

    return (
        '<a class="recipe-card" href="#/recipes/' + recipe.id + '">' +
        '<div class="recipe-card-img">' + recipeImageHtml(recipe) + '</div>' +
        '<div class="recipe-card-body">' +
        '<p class="recipe-card-title">' + escapeHtml(recipe.name) + '</p>' +
        '<div class="d-flex flex-wrap gap-1">' + difficultyBadgeHtml(recipe.difficulty) + ' ' + dietBadgesHtml(recipe) + '</div>' +
        starRatingHtml(recipe.average_rating, recipe.rating_count) +
        '<div class="recipe-card-meta">' +
        (time ? '<span><i class="bi bi-clock"></i>' + escapeHtml(time) + '</span>' : '') +
        '<span><i class="bi bi-people"></i>' + escapeHtml(recipe.servings) + '</span>' +
        (recipe.visibility !== 'public' && VISIBILITY_META[recipe.visibility]
            ? '<span><i class="bi ' + VISIBILITY_META[recipe.visibility].icon + '"></i>' + escapeHtml(t(VISIBILITY_META[recipe.visibility].labelKey)) + '</span>'
            : '') +
        '</div>' +
        (tags ? '<div class="d-flex flex-wrap gap-1 mt-1">' + tags + '</div>' : '') +
        '</div>' +
        '</a>'
    );
}

/**
 * Every recipe image is rendered as `<img data-recipe-image="/api/path">`
 * with no `src` (see recipeCardHtml()/views/recipe-detail.js), then filled
 * in here via an authenticated fetch() + object URL - a plain `src="..."`
 * pointed at the API can't send the Authorization header a *private*
 * recipe's image requires (GET /recipes/{id}/images/{imageId} re-checks
 * that recipe's own visibility on every request, same as the recipe
 * itself). A public recipe's image happens to also work with a bare src=,
 * but routing every recipe image through the same authenticated path
 * avoids two different code paths for what should be one <img> case.
 * Call this once after any render that may have inserted such <img> tags.
 * Revokes the previous batch of object URLs first, since each is created
 * fresh here and nothing else in the app holds a reference to reuse.
 */
let activeImageObjectUrls = [];

// todo.md "PWA/Offline Capability" - a tiny inline SVG (a plain data URI, no
// network request, so it's guaranteed available even on a completely cold/
// offline boot) swapped in for a recipe's own uploaded photo whenever it
// can't be fetched. Deliberately generic, not the keyword-matched
// placeholder a recipe without any photo of its own gets - that matching
// only ever happens server-side (PlaceholderImageMatcher), and there's no
// server round trip available to redo it when this fetch itself just failed.
const OFFLINE_IMAGE_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#9c9389" stroke-width="1.5">' +
    '<rect x="3" y="3" width="18" height="18" rx="2"/>' +
    '<circle cx="8.5" cy="8.5" r="1.5"/>' +
    '<path d="M21 15l-5-5L5 21"/>' +
    '</svg>'
);

async function hydrateAuthImages(root) {
    activeImageObjectUrls.forEach((url) => URL.revokeObjectURL(url));
    activeImageObjectUrls = [];

    const images = Array.from((root || document).querySelectorAll('img[data-recipe-image]'));
    await Promise.all(images.map(async (img) => {
        try {
            const url = await Kochbuch.fetchImageObjectUrl(img.dataset.recipeImage);
            activeImageObjectUrls.push(url);
            img.src = url;
        } catch (e) {
            // todo.md "PWA/Offline Capability" - recipe photos are
            // deliberately never cached for offline use, so this failure
            // path is the normal, expected outcome while offline (as well
            // as any other fetch failure, e.g. a 403) - show a placeholder
            // instead of silently vanishing.
            img.src = OFFLINE_IMAGE_PLACEHOLDER;
            img.classList.add('is-placeholder');
        }
    }));
}

function recipeGridHtml(recipes) {
    if (recipes.length === 0) {
        return (
            '<div class="empty-state"><i class="bi bi-journal-x"></i>' +
            '<p class="mb-0">' + escapeHtml(t('recipe.none_found')) + '</p></div>'
        );
    }

    return '<div class="recipe-grid">' + recipes.map(recipeCardHtml).join('') + '</div>';
}

let currentUser = null;

// todo.md "PWA/Offline Capability" - the last successful /auth/me payload,
// so isOwner()/currentUser-dependent UI (Edit/Delete buttons, etc.) keeps
// working while offline instead of silently losing owner status the moment
// the network call fails.
const CURRENT_USER_CACHE_KEY = 'kochbuch_cached_current_user';

async function loadCurrentUser() {
    if (!Kochbuch.isLoggedIn()) {
        currentUser = null;
        localStorage.removeItem(CURRENT_USER_CACHE_KEY);

        return null;
    }
    try {
        currentUser = await Kochbuch.get('/auth/me');
        localStorage.setItem(CURRENT_USER_CACHE_KEY, JSON.stringify(currentUser));
    } catch (e) {
        // Only a real 401 means the token is actually invalid - anything
        // else (offline, timeout, a flaky connection) must NOT log the user
        // out, or the app would deauthenticate itself the moment it loses
        // signal, before any offline data even gets a chance to render.
        if (e.status === 401) {
            Kochbuch.setToken(null);
            currentUser = null;
            localStorage.removeItem(CURRENT_USER_CACHE_KEY);
        } else {
            try {
                currentUser = JSON.parse(localStorage.getItem(CURRENT_USER_CACHE_KEY));
            } catch (parseError) {
                currentUser = null;
            }
        }
    }

    return currentUser;
}

const DIACRITIC_FOLD_MAP = {
    'æ': 'ae', 'œ': 'oe', 'ø': 'o', 'ß': 'ss',
    'ł': 'l', 'đ': 'd', 'ð': 'd', 'þ': 'th', 'ı': 'i',
};
// Unicode combining-marks block (accents left over after NFD decomposition).
const COMBINING_MARKS_RE = new RegExp('[\\u0300-\\u036f]', 'g');

/**
 * Case-/diacritic-insensitive normalization for search comparisons (ported
 * from YTAN's helper.js, see todo.md's "Benutzerverwaltung ... Gestaltung
 * übernommen" - used by admin-user.js's search). NFD decomposes accented
 * letters into base letter + combining mark (umlauts, French/Scandinavian/
 * Eastern-European accents), the replace() strips the mark. A handful of
 * standalone special letters with no NFD decomposition (æ, ø, ß, ł, đ, ð,
 * þ, ı) are mapped via DIACRITIC_FOLD_MAP first.
 */
function foldSearchText(str) {
    return str
        .toLowerCase()
        .replace(/[æœøłđðþıß]/g, (ch) => DIACRITIC_FOLD_MAP[ch])
        .normalize('NFD')
        .replace(COMBINING_MARKS_RE, '');
}

function isOwner(recipeOrCategory) {
    return !!currentUser && (currentUser.id === recipeOrCategory.user_id || currentUser.is_admin);
}

/**
 * Show/hide toggle for a password <input>, wired via onclick="" on a
 * Bootstrap input-group-appended button holding a single <i class="bi
 * bi-eye">. Mirrors YTAN's public/js/helper.js togglePasswordVisibility()
 * (Bootstrap-Icons flavor - YTAN also has a Material-icons flavor for its
 * own SPA, not needed here since Kochbuch only ever uses Bootstrap Icons).
 */
function togglePasswordVisibility(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', t(reveal ? 'common.hide_password' : 'common.show_password'));
    icon.classList.toggle('bi-eye', !reveal);
    icon.classList.toggle('bi-eye-slash', reveal);
}

/**
 * HTML for a password <input> wrapped in a Bootstrap input-group with a
 * show/hide toggle button, so every password field in the app looks and
 * behaves the same. `attrs` is appended verbatim to the <input> tag (e.g.
 * ' autocomplete="current-password" required').
 *
 * tabindex="-1" on the toggle button: without it, the button is a normal
 * Tab-stop sitting right after the password input, so pressing Tab after
 * typing a password lands on the eye icon instead of the next real field
 * (todo.md "Password anzeigen"). tabindex="-1" only removes it from the
 * natural Tab sequence - it's still a real, focusable, accessible <button>
 * that mouse clicks, Enter/Space (once focused another way), and screen
 * readers all still work with, unlike replacing it with a non-interactive
 * element would be.
 */
function passwordInputHtml(inputId, attrs) {
    return (
        '<div class="input-group">' +
        '<input type="password" class="form-control" id="' + inputId + '"' + (attrs || '') + '>' +
        '<button type="button" class="btn btn-outline-secondary" tabindex="-1" onclick="togglePasswordVisibility(\'' + inputId + '\', this);" aria-label="' + escapeHtml(t('common.show_password')) + '"><i class="bi bi-eye"></i></button>' +
        '</div>'
    );
}
