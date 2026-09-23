/**
 * Small stateless helpers shared across public/js/views/*.js.
 */

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);

    return div.innerHTML;
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

function recipeCardHtml(recipe) {
    const imagePath = recipeImagePath(recipe);
    const time = timeLabel(totalTimeMinutes(recipe));
    const tags = (recipe.tags || []).slice(0, 3)
        .map((tag) => '<span class="tag-chip">' + escapeHtml(tag) + '</span>')
        .join(' ');

    return (
        '<a class="recipe-card" href="#/recipes/' + recipe.id + '">' +
        '<div class="recipe-card-img">' +
        (imagePath ? '<img data-recipe-image="' + escapeHtml(imagePath) + '" alt="">' : '<i class="bi bi-egg-fried"></i>') +
        '</div>' +
        '<div class="recipe-card-body">' +
        '<p class="recipe-card-title">' + escapeHtml(recipe.name) + '</p>' +
        '<div class="d-flex flex-wrap gap-1">' + difficultyBadgeHtml(recipe.difficulty) + ' ' + dietBadgesHtml(recipe) + '</div>' +
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
            img.remove();
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

async function loadCurrentUser() {
    if (!Kochbuch.isLoggedIn()) {
        currentUser = null;

        return null;
    }
    try {
        currentUser = await Kochbuch.get('/auth/me');
    } catch (e) {
        Kochbuch.setToken(null);
        currentUser = null;
    }

    return currentUser;
}

function isOwner(recipeOrCategory) {
    return !!currentUser && (currentUser.id === recipeOrCategory.user_id || currentUser.is_admin);
}
