/**
 * Recipe detail screen (route "/recipes/:id"). Portion scaling is purely
 * client-side: the API always returns ingredient amounts for the recipe's
 * own base `servings`, and changing the stepper just re-renders the
 * ingredient list scaled by servings/base - no API round trip.
 */
async function renderRecipeDetail(params) {
    const app = document.getElementById('app');
    app.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    // todo.md "PWA/Offline Capability" - navigator.onLine is only reliable
    // for a confident "definitely offline" (airplane mode/no signal, not a
    // merely degraded connection) - skip the doomed network attempt
    // entirely in that case, straight to the cached copy if there is one,
    // rather than waiting out the full request timeout first. The
    // background/manual full sync (nav.js's performFullSync()) means every
    // visible recipe is normally already here, not just ones actually opened
    // before.
    if (!navigator.onLine) {
        const cached = await OfflineStore.getRecipe(params.id);
        if (cached) {
            renderRecipeDetailFromData(cached, true);

            return;
        }
    }

    let recipe;
    let offline = false;
    try {
        // A short, offline-fallback-specific timeout (see offline-store.js)
        // rather than api-client.js's normal 15s default - a stalled/poor
        // connection (as opposed to a clean, instant offline failure)
        // should still give up and fall back quickly here.
        recipe = await Kochbuch.get('/recipes/' + params.id, OFFLINE_FALLBACK_TIMEOUT_MS);
        // todo.md "PWA/Offline Capability" - fire-and-forget: every recipe
        // the user actually opens becomes available offline automatically,
        // on top of whatever the full sync already stored (see nav.js's
        // performFullSync()).
        OfflineStore.saveRecipe(recipe);
    } catch (e) {
        recipe = await OfflineStore.getRecipe(params.id);
        if (!recipe) {
            app.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(e.data) || e.message) + '</div>';

            return;
        }
        offline = true;
    }

    renderRecipeDetailFromData(recipe, offline);
}

function renderRecipeDetailFromData(recipe, offline) {
    const app = document.getElementById('app');
    let servings = recipe.servings;
    app.innerHTML = recipeDetailHtml(recipe, servings, offline);
    wireRecipeDetail(recipe, () => servings, (v) => { servings = v; });
    hydrateAuthImages(app);
    acquireWakeLockIfEnabled();
}

/**
 * todo.md "Disabling the Screen Lock on Smartphones" - on by default
 * (settings.js's loadSettings()), scoped to "while viewing the details of a
 * recipe (and only then)": acquired here, released the moment the hash
 * navigates away from a recipe detail route (see the hashchange listener
 * below). Screen Wake Lock isn't supported everywhere yet (e.g. older
 * Safari) - 'wakeLock' in navigator guards that, and the recipe is fully
 * usable either way, just without the screen staying on.
 */
let activeWakeLock = null;

async function acquireWakeLockIfEnabled() {
    // Covers recipe-to-recipe navigation: the hashchange listener below
    // only releases when leaving the detail route entirely, so a stale
    // sentinel from the previous recipe would otherwise never be released.
    releaseWakeLock();
    if (!loadSettings().keepScreenAwake || !('wakeLock' in navigator)) {
        return;
    }
    try {
        activeWakeLock = await navigator.wakeLock.request('screen');
    } catch (e) {
        // Denied/unsupported in this context (e.g. document not visible
        // yet) - not fatal, the page just won't stay awake.
        activeWakeLock = null;
    }
}

function releaseWakeLock() {
    if (!activeWakeLock) {
        return;
    }
    activeWakeLock.release().catch(() => {});
    activeWakeLock = null;
}

function isOnRecipeDetailRoute() {
    return /^#\/recipes\/\d+$/.test(location.hash);
}

// A held wake lock is automatically released by the browser whenever the
// document is hidden (tab switched, app backgrounded) - re-acquire it on
// return if the user is still on a recipe detail page, otherwise it would
// silently stop working after the very first app-switch while cooking.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && isOnRecipeDetailRoute()) {
        acquireWakeLockIfEnabled();
    }
});

// Releases the lock the moment the hash navigates away from a recipe detail
// route - renderRecipeDetail() re-acquires a fresh one on arrival at another
// recipe, so recipe-to-recipe navigation is covered too.
window.addEventListener('hashchange', () => {
    if (!isOnRecipeDetailRoute()) {
        releaseWakeLock();
    }
});

function recipeHeroHtml(recipe) {
    const imagePath = recipeImagePath(recipe);
    if (imagePath) {
        return '<img id="heroImage" data-recipe-image="' + escapeHtml(imagePath) + '" alt="">';
    }
    if (recipe.placeholder_image_filename) {
        // todo.md "Image and Placeholder Scaling" - see .is-placeholder in style.css.
        return '<img id="heroImage" class="is-placeholder" src="' + escapeHtml(placeholderImageUrl(recipe.placeholder_image_filename)) + '" alt="">';
    }

    return '<i class="bi bi-egg-fried"></i>';
}

/**
 * todo.md "Displaying recipe images" - prev/next arrows overlaid on the
 * hero image, only shown once there's actually more than one uploaded image
 * to browse between (a placeholder or the single-image case has nothing to
 * scroll through). Wired in wireImageGallery(), which already tracks the
 * currently-shown image for the thumbnail-click-to-preview behavior.
 */
function heroNavHtml(recipe) {
    if (!recipe.images || recipe.images.length <= 1) {
        return '';
    }

    return (
        '<button type="button" class="hero-nav-btn hero-nav-prev" id="heroPrevBtn" aria-label="' + escapeHtml(t('recipe.previous_image')) + '"><i class="bi bi-chevron-left"></i></button>' +
        '<button type="button" class="hero-nav-btn hero-nav-next" id="heroNextBtn" aria-label="' + escapeHtml(t('recipe.next_image')) + '"><i class="bi bi-chevron-right"></i></button>'
    );
}

function recipeDetailHtml(recipe, servings, offline) {
    const owner = isOwner(recipe);

    return (
        '<div class="mb-3"><a href="' + escapeHtml(lastRecipesListUrl) + '" class="link-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> ' + escapeHtml(t('recipe.back_to_list')) + '</a></div>' +
        (offline ? '<div class="alert alert-secondary py-2 small"><i class="bi bi-cloud-slash"></i> ' + escapeHtml(t('recipe.offline_cached_notice')) + '</div>' : '') +
        '<div class="recipe-hero">' + recipeHeroHtml(recipe) + heroNavHtml(recipe) + '</div>' +
        '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">' +
        '<div>' +
        '<h1 class="h3 mb-0">' + escapeHtml(recipe.name) + '</h1>' +
        // Grouped with the name (not a sibling after this whole row) so the
        // buttons wrapping onto their own line on a narrow screen can never
        // land between the name and its byline.
        (recipe.owner_username ? '<p class="text-muted small mb-0">' + escapeHtml(t('recipe.by_author', { username: recipe.owner_username })) + '</p>' : '') +
        '</div>' +
        '<div class="d-flex gap-2 flex-wrap">' +
        shareButtonHtml() +
        exportMenuHtml(recipe.id) +
        bringButtonHtml() +
        (Kochbuch.isLoggedIn() ? duplicateButtonHtml() : '') +
        (owner ? '<a href="#/recipes/' + recipe.id + '/edit" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i></a>' : '') +
        (owner ? '<button type="button" class="btn btn-outline-danger" id="deleteRecipeBtn"><i class="bi bi-trash"></i></button>' : '') +
        '</div></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-3">' +
        difficultyBadgeHtml(recipe.difficulty) + dietBadgesHtml(recipe) +
        (VISIBILITY_META[recipe.visibility]
            ? '<span class="badge text-bg-secondary"><i class="bi ' + VISIBILITY_META[recipe.visibility].icon + '"></i> ' + escapeHtml(t(VISIBILITY_META[recipe.visibility].labelKey)) + '</span>'
            : '') +
        '</div>' +
        recipeRatingHtml(recipe) +
        (recipe.description ? '<p class="mb-4">' + escapeHtml(recipe.description).replace(/\n/g, '<br>') + '</p>' : '') +

        '<div class="row g-4">' +
        '<div class="col-lg-5">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<h2 class="h5 mb-0">' + escapeHtml(t('recipe.ingredients')) + '</h2>' +
        '<div class="portion-stepper">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="portionMinus">-</button>' +
        '<span class="portion-value" id="portionValue">' + servings + '</span>' +
        '<span class="text-muted small">' + escapeHtml(t('recipe.servings_label')) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="portionPlus">+</button>' +
        '</div></div>' +
        '<ul class="ingredient-list" id="ingredientList"></ul>' +
        metaInfoHtml(recipe) +
        categoryPanelHtml(recipe) +
        '</div>' +
        '<div class="col-lg-7">' +
        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.steps')) + '</h2>' +
        '<ol class="step-list">' + recipe.steps.map((s) => (
            s.is_heading
                ? '<li class="step-heading">' + escapeHtml(s.instruction) + '</li>'
                : '<li>' + escapeHtml(s.instruction).replace(/\n/g, '<br>') + '</li>'
        )).join('') + '</ol>' +
        (recipe.notes ? '<h2 class="h5 mt-4 mb-2">' + escapeHtml(t('recipe.notes')) + '</h2><p>' + escapeHtml(recipe.notes).replace(/\n/g, '<br>') + '</p>' : '') +
        imageGalleryHtml(recipe, owner) +
        '</div>' +
        '</div>'
    );
}

function shareButtonHtml() {
    return '<button type="button" class="btn btn-outline-secondary" id="shareRecipeBtn"><i class="bi bi-share"></i></button>';
}

function exportMenuHtml(id) {
    return (
        '<div class="dropdown">' +
        '<button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-download"></i></button>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        ['json', 'pdf', 'xml'].map((f) => '<li><a class="dropdown-item recipe-export-link" href="#" data-format="' + f + '">' + f.toUpperCase() + '</a></li>').join('') +
        '</ul></div>'
    );
}

/**
 * Sends the ingredient list to the Bring! shopping-list app (todo.md
 * "Anbindung der Einkaufs-App Bring!") - works for any recipe the current
 * user can view (private/internal/public), not just public ones: the
 * backend mints a short-lived, unguessable link for Bring!'s server to
 * fetch instead of relying on the recipe's normal visibility (see
 * RecipeController::bringExport()).
 */
function bringButtonHtml() {
    return '<button type="button" class="btn btn-outline-secondary" id="sendToBringBtn" title="' + escapeHtml(t('recipe.send_to_bring')) + '"><i class="bi bi-basket2-fill"></i></button>';
}

/**
 * todo.md "Rezept duplizieren" - available for any recipe the current
 * viewer can see (own or someone else's visible one), not just the owner's
 * - unlike edit/delete, making a variant of your own recipe is just as
 * valid a use case as copying someone else's. Shown for any logged-in
 * viewer (duplicating requires being able to save a new recipe at all).
 */
function duplicateButtonHtml() {
    return '<button type="button" class="btn btn-outline-secondary" id="duplicateRecipeBtn" title="' + escapeHtml(t('recipe.duplicate')) + '"><i class="bi bi-copy"></i></button>';
}

/**
 * Average rating (read-only, same starRatingHtml() as the recipe cards)
 * plus, for a logged-in viewer, a clickable 1-5 star "your rating" picker -
 * distinct markup/classes from the primary-image star toggle
 * (imageThumbHtml()) since that one is a binary switch, this is a 1-5
 * scale. Logged-out visitors never see the picker (there's nothing to
 * rate with), matching the edit/delete buttons' own auth-gating above.
 */
function recipeRatingHtml(recipe) {
    // starRatingHtml() already appends "{average} ({count})" next to the
    // stars (todo.md "Displaying Star Ratings") - no separate count line
    // needed here anymore.
    const average = starRatingHtml(recipe.average_rating, recipe.rating_count)
        || '<span class="text-muted small">' + escapeHtml(t('recipe.rating.none_yet')) + '</span>';

    const picker = Kochbuch.isLoggedIn()
        ? '<div class="rating-picker mt-1" id="ratingPicker">' +
          '<span class="text-muted small me-1">' + escapeHtml(t('recipe.rating.your_rating')) + ':</span>' +
          [1, 2, 3, 4, 5].map((n) =>
              '<button type="button" class="rating-picker-star" data-rate="' + n + '" aria-label="' + n + '">' +
              '<i class="bi ' + ((recipe.my_rating && n <= recipe.my_rating) ? 'bi-star-fill is-filled' : 'bi-star') + '"></i></button>'
          ).join('') +
          (recipe.my_rating
              ? '<button type="button" class="btn btn-sm btn-link text-muted p-0 ms-2" id="removeRatingBtn">' + escapeHtml(t('recipe.rating.remove')) + '</button>'
              : '') +
          '</div>'
        : '';

    return (
        '<div class="mb-3" id="recipeRatingBlock">' +
        '<div class="d-flex align-items-center gap-2 flex-wrap">' + average + '</div>' +
        picker +
        '</div>'
    );
}

function metaInfoHtml(recipe) {
    const rows = [];
    const time = timeLabel(totalTimeMinutes(recipe));
    if (time) rows.push([t('recipe.total_time'), time]);
    if (recipe.calories) rows.push([t('recipe.calories'), recipe.calories]);
    if (recipe.allergen_info) rows.push([t('recipe.allergen_info'), recipe.allergen_info]);
    if (recipe.source || recipe.source_url) {
        const sourceHtml = recipe.source_url
            ? '<a href="' + escapeHtml(recipe.source_url) + '" target="_blank" rel="noopener">' + escapeHtml(recipe.source || recipe.source_url) + '</a>'
            : escapeHtml(recipe.source);
        rows.push([t('recipe.source'), sourceHtml]);
    }
    if (!rows.length && !(recipe.tags || []).length) {
        return '';
    }

    return (
        '<div class="mt-4 small">' +
        rows.map(([label, value]) => '<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted">' + escapeHtml(label) + '</span><span>' + value + '</span></div>').join('') +
        (recipe.tags.length ? '<div class="d-flex flex-wrap gap-1 mt-2">' + recipe.tags.map((tg) => '<span class="tag-chip">' + escapeHtml(tg) + '</span>').join('') + '</div>' : '') +
        '</div>'
    );
}

function categoryPanelHtml(recipe) {
    if (!Kochbuch.isLoggedIn()) {
        return '';
    }

    return (
        '<div class="mt-4">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="categoryToggleBtn"><i class="bi bi-collection"></i> ' + escapeHtml(t('category.add_to')) + '</button>' +
        '<div id="categoryChecklist" class="mt-2 d-none"></div>' +
        '</div>'
    );
}

function imageGalleryHtml(recipe, owner) {
    if (!owner && recipe.images.length <= 1) {
        return '';
    }

    return (
        '<h2 class="h5 mt-4 mb-2">' + escapeHtml(t('recipe.images')) + '</h2>' +
        '<div class="image-thumb-grid" id="imageThumbGrid">' +
        recipe.images.map((id) => imageThumbHtml(recipe, id, owner)).join('') +
        (owner ? '<label class="btn btn-outline-secondary image-thumb d-flex align-items-center justify-content-center" style="cursor:pointer">' +
            '<i class="bi bi-plus-lg"></i><input type="file" id="imageUploadInput" accept="image/jpeg,image/png,image/webp" class="d-none"></label>' : '') +
        '</div>'
    );
}

function imageThumbHtml(recipe, imageId, owner) {
    const path = '/recipes/' + recipe.id + '/images/' + imageId;
    const isPrimary = recipe.primary_image_id === imageId;
    // todo.md "Recipe images" - only meaningful to offer once there's
    // something to choose between; with a single image it's already the
    // default automatically (RecipeRepository::addImage()).
    const canPickDefault = owner && recipe.images.length > 1;

    return (
        '<div class="image-thumb' + (isPrimary ? ' is-primary' : '') + '" data-image-id="' + imageId + '">' +
        '<img data-recipe-image="' + escapeHtml(path) + '" alt="">' +
        (canPickDefault
            ? '<button type="button" class="image-thumb-default-btn" data-set-default-image="' + imageId + '"' +
              (isPrimary ? ' disabled' : '') +
              ' aria-label="' + escapeHtml(t(isPrimary ? 'recipe.default_image' : 'recipe.set_default_image')) + '"' +
              ' title="' + escapeHtml(t(isPrimary ? 'recipe.default_image' : 'recipe.set_default_image')) + '">' +
              '<i class="bi ' + (isPrimary ? 'bi-star-fill' : 'bi-star') + '"></i></button>'
            : '') +
        (owner ? '<button type="button" class="image-thumb-remove" data-remove-image="' + imageId + '">&times;</button>' : '') +
        '</div>'
    );
}

function wireRecipeDetail(recipe, getServings, setServings) {
    const bringBtn = document.getElementById('sendToBringBtn');
    bringBtn.addEventListener('click', () => withBusyButton(bringBtn, async () => {
        const result = await Kochbuch.post('/recipes/' + recipe.id + '/bring-export', { requested_servings: getServings() });
        window.open(result.deeplink, '_blank');
    }));

    renderIngredients(recipe, getServings());

    document.getElementById('portionMinus').addEventListener('click', () => {
        const next = Math.max(1, getServings() - 1);
        setServings(next);
        document.getElementById('portionValue').textContent = next;
        renderIngredients(recipe, next);
    });
    document.getElementById('portionPlus').addEventListener('click', () => {
        const next = getServings() + 1;
        setServings(next);
        document.getElementById('portionValue').textContent = next;
        renderIngredients(recipe, next);
    });

    document.getElementById('shareRecipeBtn').addEventListener('click', async () => {
        const url = location.origin + location.pathname + '#/recipes/' + recipe.id;
        if (navigator.share) {
            try {
                await navigator.share({ title: recipe.name, url });
            } catch (e) { /* user cancelled */ }

            return;
        }
        await navigator.clipboard.writeText(url);
        showToast(t('recipe.share_copied'));
    });

    const duplicateBtn = document.getElementById('duplicateRecipeBtn');
    if (duplicateBtn) {
        duplicateBtn.addEventListener('click', () => {
            // todo.md "Rezept duplizieren" - same sessionStorage handoff
            // pattern as the OCR-import draft (recipe-import-photo.js ->
            // recipe-form.js), seeded from the already-loaded recipe
            // instead of an OCR result. Images are deliberately never
            // carried over (upload only works once a recipe has an id
            // anyway, see CLAUDE.md "Recipe image upload UX") and
            // `visibility` resets to the normal new-recipe default rather
            // than copying the source's - duplicating someone else's
            // public recipe must never silently republish it as public
            // under the new owner without them deciding to. source/
            // source_url ARE kept, so the copy still credits where it
            // came from.
            sessionStorage.setItem('kochbuch_recipe_duplicate_draft', JSON.stringify({
                name: recipe.name + t('recipe.duplicate_name_suffix'),
                description: recipe.description,
                servings: recipe.servings,
                difficulty: recipe.difficulty,
                prep_time_minutes: recipe.prep_time_minutes,
                rest_time_minutes: recipe.rest_time_minutes,
                cook_time_minutes: recipe.cook_time_minutes,
                calories: recipe.calories,
                allergen_info: recipe.allergen_info,
                notes: recipe.notes,
                source: recipe.source,
                source_url: recipe.source_url,
                is_vegan: recipe.is_vegan,
                is_vegetarian: recipe.is_vegetarian,
                is_pescetarian: recipe.is_pescetarian,
                ingredients: recipe.ingredients,
                steps: recipe.steps,
                tags: recipe.tags,
            }));
            Router.navigate('/recipes/new?duplicateFrom=1');
        });
    }

    document.querySelectorAll('.recipe-export-link').forEach((link) => {
        link.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await Kochbuch.download('/recipes/' + recipe.id + '/export/' + link.dataset.format);
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    const deleteBtn = document.getElementById('deleteRecipeBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            if (!await showConfirmDialog(t('recipe.delete_confirm'), { type: 'danger' })) {
                return;
            }
            try {
                await Kochbuch.del('/recipes/' + recipe.id);
                showToast(t('recipe.deleted'));
                Router.navigate('/recipes');
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }

    wireCategoryPanel(recipe);
    wireImageGallery(recipe);
    wireRatingPicker(recipe);
}

/**
 * Clicking star `n` rates the recipe `n` (an upsert server-side, see
 * RecipeController::rate() - re-rating just updates the user's existing
 * value). Updates just the rating block in place rather than reloading the
 * whole detail view (renderRecipeDetail()) - RecipeController::rate()/
 * deleteRating() already return the full updated recipe (fresh
 * average_rating/rating_count/my_rating included) in their response, so no
 * second GET is even needed, let alone a full re-render that would flash
 * the loading spinner, re-fetch every image, and reset scroll position just
 * to reflect a star click.
 */
function wireRatingPicker(recipe) {
    const picker = document.getElementById('ratingPicker');
    if (!picker) {
        return;
    }

    const applyUpdatedRating = (updated) => {
        recipe.average_rating = updated.average_rating;
        recipe.rating_count = updated.rating_count;
        recipe.my_rating = updated.my_rating;
        document.getElementById('recipeRatingBlock').outerHTML = recipeRatingHtml(recipe);
        // outerHTML replacement drops the old elements' listeners - rewire
        // the fresh ones (including, if cleared, that the "remove" button
        // state now correctly reflects whether my_rating is still set).
        wireRatingPicker(recipe);
    };

    picker.querySelectorAll('[data-rate]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            try {
                const updated = await Kochbuch.put('/recipes/' + recipe.id + '/rating', { rating: parseInt(btn.dataset.rate, 10) });
                applyUpdatedRating(updated);
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    const removeBtn = document.getElementById('removeRatingBtn');
    if (removeBtn) {
        removeBtn.addEventListener('click', async () => {
            try {
                const updated = await Kochbuch.del('/recipes/' + recipe.id + '/rating');
                applyUpdatedRating(updated);
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }
}

function renderIngredients(recipe, servings) {
    const factor = servings / recipe.servings;
    document.getElementById('ingredientList').innerHTML = recipe.ingredients.map((i) => {
        if (i.is_heading) {
            return '<li class="ingredient-heading">' + escapeHtml(i.name) + '</li>';
        }

        const amount = i.amount !== null ? formatAmount(i.amount * factor) : '';
        const amountUnit = [amount, i.unit].filter(Boolean).join(' ');

        // Left to right: amount, unit, ingredient, (note).
        return (
            '<li><span class="ingredient-amount text-nowrap fw-semibold">' + escapeHtml(amountUnit) + '</span>' +
            '<span>' + escapeHtml(i.name) + (i.note ? ' <span class="text-muted small">(' + escapeHtml(i.note) + ')</span>' : '') + '</span></li>'
        );
    }).join('');
}

async function wireCategoryPanel(recipe) {
    const toggleBtn = document.getElementById('categoryToggleBtn');
    if (!toggleBtn) {
        return;
    }
    const panel = document.getElementById('categoryChecklist');

    const loadChecklist = async () => {
        try {
            const categories = await Kochbuch.get('/categories?recipe_id=' + recipe.id);
            panel.innerHTML =
                (categories.length
                    ? categories.map((c) =>
                        '<div class="form-check">' +
                        '<input class="form-check-input category-toggle" type="checkbox" id="cat' + c.id + '" data-category-id="' + c.id + '"' + (c.contains_recipe ? ' checked' : '') + '>' +
                        '<label class="form-check-label" for="cat' + c.id + '">' + escapeHtml(c.name) + '</label></div>'
                    ).join('')
                    : '<p class="small text-muted mb-0">' + escapeHtml(t('category.none_yet')) + '</p>') +
                '<form id="newCategoryInlineForm" class="input-group input-group-sm mt-2">' +
                '<input type="text" class="form-control" id="newCategoryInlineName" placeholder="' + escapeHtml(t('category.new_placeholder')) + '" required>' +
                '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i></button>' +
                '</form>';

            panel.querySelectorAll('.category-toggle').forEach((cb) => {
                cb.addEventListener('change', async () => {
                    const categoryId = cb.dataset.categoryId;
                    try {
                        if (cb.checked) {
                            await Kochbuch.put('/categories/' + categoryId + '/recipes/' + recipe.id);
                        } else {
                            await Kochbuch.del('/categories/' + categoryId + '/recipes/' + recipe.id);
                        }
                    } catch (err) {
                        cb.checked = !cb.checked;
                        showToast(translateApiError(err.data) || err.message, 'danger');
                    }
                });
            });

            document.getElementById('newCategoryInlineForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const input = document.getElementById('newCategoryInlineName');
                const name = input.value.trim();
                if (!name) {
                    return;
                }
                // todo.md "Loading Indicator During Longer Processes"
                await withBusyButton(e.submitter, async () => {
                    const category = await Kochbuch.post('/categories', { name });
                    await Kochbuch.put('/categories/' + category.id + '/recipes/' + recipe.id);
                    await loadChecklist();
                });
            });
        } catch (err) {
            panel.innerHTML = '<p class="text-danger small">' + escapeHtml(translateApiError(err.data) || err.message) + '</p>';
        }
    };

    toggleBtn.addEventListener('click', async () => {
        panel.classList.toggle('d-none');
        if (panel.classList.contains('d-none') || panel.dataset.loaded) {
            return;
        }
        panel.dataset.loaded = '1';
        await loadChecklist();
    });
}

function wireImageGallery(recipe) {
    const grid = document.getElementById('imageThumbGrid');
    if (!grid) {
        return;
    }

    // todo.md "Displaying recipe images" - the thumbnails are already being
    // hydrated with authenticated object-URL srcs (see hydrateAuthImages(),
    // called once after this whole view is wired), same order as
    // recipe.images, so the hero prev/next arrows just copy from them
    // instead of re-fetching anything. currentIndex starts on whichever
    // image is already shown as the hero (the primary one).
    const thumbImgs = Array.from(grid.querySelectorAll('.image-thumb img'));
    let currentIndex = Math.max(0, recipe.images.indexOf(recipe.primary_image_id));

    function showImageAt(index) {
        const hero = document.getElementById('heroImage');
        if (!hero || thumbImgs.length === 0) {
            return;
        }
        currentIndex = (index + thumbImgs.length) % thumbImgs.length;
        hero.src = thumbImgs[currentIndex].src;
    }

    thumbImgs.forEach((img, index) => {
        img.addEventListener('click', () => showImageAt(index));
    });

    const prevBtn = document.getElementById('heroPrevBtn');
    const nextBtn = document.getElementById('heroNextBtn');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => showImageAt(currentIndex - 1));
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => showImageAt(currentIndex + 1));
    }

    grid.querySelectorAll('[data-set-default-image]').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await Kochbuch.put('/recipes/' + recipe.id + '/primary-image', { image_id: parseInt(btn.dataset.setDefaultImage, 10) });
                renderRecipeDetail({ id: recipe.id });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    grid.querySelectorAll('[data-remove-image]').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            // todo.md "Deleting recipe images" - same confirm-before-delete
            // pattern as deleting the whole recipe (deleteRecipeBtn above).
            if (!await showConfirmDialog(t('recipe.remove_image_confirm'), { type: 'danger' })) {
                return;
            }
            try {
                await Kochbuch.del('/recipes/' + recipe.id + '/images/' + btn.dataset.removeImage);
                renderRecipeDetail({ id: recipe.id });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    const uploadInput = document.getElementById('imageUploadInput');
    if (uploadInput) {
        uploadInput.addEventListener('change', async () => {
            const file = uploadInput.files[0];
            if (!file) {
                return;
            }
            const formData = new FormData();
            formData.append('image', file);
            try {
                await Kochbuch.upload('/recipes/' + recipe.id + '/images', formData);
                renderRecipeDetail({ id: recipe.id });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }
}
