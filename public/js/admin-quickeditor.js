/**
 * Admin "Schnell-Editor" (/admin/quickeditor; todo.md "Quick Editor", grown out of
 * "Schnelle Tag-Zuordnung im Admin-Bereich"). Per recipe, straight from the
 * list: owner, name, servings, diet (none/vegan/vegetarian/pescetarian) and
 * visibility via PUT /admin/recipes/{id}/quick (QuickEditController - only
 * the changed field is sent, ingredients/steps stay untouched), tags via
 * PUT /recipes/{id}/tags, and "delete" moves the recipe to the admin trash
 * (DELETE /recipes/{id}, soft delete). The search can be narrowed to one
 * owner.
 *
 * Originally the quick tag assignment page: a recipe list filterable the same way as
 * the homepage (public/js/views/recipes-list.js), but server-side filtered/
 * paginated against the admin-only GET /admin/recipes (which - unlike the
 * homepage's GET /recipes - bypasses visibility, so every recipe shows up
 * regardless of owner), with an inline add/remove tag editor per row via
 * PUT /recipes/{id}/tags (a tags-only endpoint, deliberately not the full
 * recipe PUT, which would wipe ingredients/steps if not resent).
 *
 * Pagination reuses ADMIN_LIST_PAGE_SIZES/ADMIN_LIST_DEFAULT_PAGE_SIZE
 * (public/js/config.js) - the admin-wide convention - rather than the
 * homepage's RECIPE_PAGE_SIZES, which isn't injected on admin pages.
 */
let adminQuickEditorCategories = [];
let adminQuickEditorUsers = [];
let adminQuickEditorLastResult = { items: [], total: 0, page: 1, per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE };
let adminQuickEditorQuery = {
    q: '',
    difficulty: '',
    vegan: false,
    vegetarian: false,
    pescetarian: false,
    no_diet: false,
    untagged: false,
    category_id: '',
    owner_id: '',
    page: 1,
    per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE,
};
let adminQuickEditorSearchDebounce = null;

async function initAdminQuickEditor() {
    try {
        adminQuickEditorCategories = await Kochbuch.get('/categories');
    } catch (e) { /* filter dropdown just stays limited to "all categories" */ }
    try {
        adminQuickEditorUsers = await Kochbuch.get('/users');
    } catch (e) { /* owner filter/select stay empty */ }

    renderAdminQuickEditorFilters();
    loadAdminQuickEditorList();
}

function renderAdminQuickEditorFilters() {
    const el = document.getElementById('adminQuickEditorFilters');

    el.innerHTML =
        '<div class="input-group mb-2">' +
        '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
        '<input type="search" class="form-control" id="adminQuickEditorFilterQ" placeholder="' + escapeHtml(t('admin.quickeditor.search_placeholder')) + '" value="' + escapeHtml(adminQuickEditorQuery.q) + '">' +
        '</div>' +
        '<div class="d-flex flex-wrap gap-2 align-items-center">' +
        '<select class="form-select form-select-sm w-auto" id="adminQuickEditorFilterOwner">' +
        '<option value="">' + escapeHtml(t('admin.quickeditor.filter_all_owners')) + '</option>' +
        adminQuickEditorUsers.map((u) =>
            '<option value="' + u.id + '"' + (adminQuickEditorQuery.owner_id === String(u.id) ? ' selected' : '') + '>' + escapeHtml(u.username) + '</option>'
        ).join('') +
        '</select>' +
        '<select class="form-select form-select-sm w-auto" id="adminQuickEditorFilterCategory">' +
        '<option value="">' + escapeHtml(t('category.filter_all')) + '</option>' +
        adminQuickEditorCategories.map((c) =>
            '<option value="' + c.id + '"' + (adminQuickEditorQuery.category_id === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>'
        ).join('') +
        '<option value="uncategorized"' + (adminQuickEditorQuery.category_id === 'uncategorized' ? ' selected' : '') + '>' + escapeHtml(t('category.own_uncategorized')) + '</option>' +
        '</select>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminQuickEditorFilterVegan"' + (adminQuickEditorQuery.vegan ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminQuickEditorFilterVegan">' + escapeHtml(t('diet.vegan')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminQuickEditorFilterVegetarian"' + (adminQuickEditorQuery.vegetarian ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminQuickEditorFilterVegetarian">' + escapeHtml(t('diet.vegetarian')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminQuickEditorFilterPescetarian"' + (adminQuickEditorQuery.pescetarian ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminQuickEditorFilterPescetarian">' + escapeHtml(t('diet.pescetarian')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminQuickEditorFilterNoDiet"' + (adminQuickEditorQuery.no_diet ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminQuickEditorFilterNoDiet">' + escapeHtml(t('admin.quickeditor.no_diet_only')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminQuickEditorFilterUntagged"' + (adminQuickEditorQuery.untagged ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminQuickEditorFilterUntagged">' + escapeHtml(t('admin.quickeditor.untagged_only')) + '</label>' +
        '</div>' +
        '<select class="form-select form-select-sm w-auto" id="adminQuickEditorFilterDifficulty">' +
        '<option value="">' + escapeHtml(t('recipe.difficulty')) + '</option>' +
        ['easy', 'normal', 'hard', 'challenging'].map((d) =>
            '<option value="' + d + '"' + (adminQuickEditorQuery.difficulty === d ? ' selected' : '') + '>' + escapeHtml(t(DIFFICULTY_LABEL_KEY[d])) + '</option>'
        ).join('') +
        '</select>' +
        '</div>';

    document.getElementById('adminQuickEditorFilterQ').addEventListener('input', () => {
        clearTimeout(adminQuickEditorSearchDebounce);
        adminQuickEditorSearchDebounce = setTimeout(() => {
            adminQuickEditorQuery.q = document.getElementById('adminQuickEditorFilterQ').value.trim();
            adminQuickEditorQuery.page = 1;
            loadAdminQuickEditorList();
        }, 350);
    });

    // "ohne Ernährungsform" and vegan/vegetarian/pescetarian exclude each
    // other - ticking one side clears the other, instead of an always-empty
    // combination.
    const dietBoxes = ['adminQuickEditorFilterVegan', 'adminQuickEditorFilterVegetarian', 'adminQuickEditorFilterPescetarian'];
    const noDietBox = document.getElementById('adminQuickEditorFilterNoDiet');
    noDietBox.addEventListener('change', () => {
        if (noDietBox.checked) {
            dietBoxes.forEach((id) => { document.getElementById(id).checked = false; });
        }
    });
    dietBoxes.forEach((id) => document.getElementById(id).addEventListener('change', (e) => {
        if (e.target.checked) {
            noDietBox.checked = false;
        }
    }));

    ['adminQuickEditorFilterOwner', 'adminQuickEditorFilterCategory', 'adminQuickEditorFilterVegan', 'adminQuickEditorFilterVegetarian', 'adminQuickEditorFilterPescetarian', 'adminQuickEditorFilterNoDiet', 'adminQuickEditorFilterUntagged', 'adminQuickEditorFilterDifficulty'].forEach((id) => {
        document.getElementById(id).addEventListener('change', () => {
            adminQuickEditorQuery.owner_id = document.getElementById('adminQuickEditorFilterOwner').value;
            adminQuickEditorQuery.category_id = document.getElementById('adminQuickEditorFilterCategory').value;
            adminQuickEditorQuery.vegan = document.getElementById('adminQuickEditorFilterVegan').checked;
            adminQuickEditorQuery.vegetarian = document.getElementById('adminQuickEditorFilterVegetarian').checked;
            adminQuickEditorQuery.pescetarian = document.getElementById('adminQuickEditorFilterPescetarian').checked;
            adminQuickEditorQuery.no_diet = document.getElementById('adminQuickEditorFilterNoDiet').checked;
            adminQuickEditorQuery.untagged = document.getElementById('adminQuickEditorFilterUntagged').checked;
            adminQuickEditorQuery.difficulty = document.getElementById('adminQuickEditorFilterDifficulty').value;
            adminQuickEditorQuery.page = 1;
            loadAdminQuickEditorList();
        });
    });
}

async function loadAdminQuickEditorList() {
    const results = document.getElementById('adminQuickEditorResults');
    results.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    try {
        const apiQuery = new URLSearchParams();
        if (adminQuickEditorQuery.q) apiQuery.set('q', adminQuickEditorQuery.q);
        if (adminQuickEditorQuery.difficulty) apiQuery.set('difficulty', adminQuickEditorQuery.difficulty);
        if (adminQuickEditorQuery.vegan) apiQuery.set('vegan', '1');
        if (adminQuickEditorQuery.vegetarian) apiQuery.set('vegetarian', '1');
        if (adminQuickEditorQuery.pescetarian) apiQuery.set('pescetarian', '1');
        if (adminQuickEditorQuery.no_diet) apiQuery.set('no_diet', '1');
        if (adminQuickEditorQuery.untagged) apiQuery.set('untagged', '1');
        if (adminQuickEditorQuery.category_id) apiQuery.set('category_id', adminQuickEditorQuery.category_id);
        if (adminQuickEditorQuery.owner_id) apiQuery.set('owner_id', adminQuickEditorQuery.owner_id);
        apiQuery.set('page', String(adminQuickEditorQuery.page));
        apiQuery.set('per_page', String(adminQuickEditorQuery.per_page));

        adminQuickEditorLastResult = await Kochbuch.get('/admin/recipes?' + apiQuery.toString());
        renderAdminQuickEditorTable();
    } catch (err) {
        results.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function adminQuickEditorSuggestionsHtml() {
    const names = new Set();
    adminQuickEditorLastResult.items.forEach((recipe) => (recipe.tags || []).forEach((tag) => names.add(tag)));

    return '<datalist id="adminQuickEditorSuggestions">' +
        Array.from(names).map((name) => '<option value="' + escapeHtml(name) + '">').join('') +
        '</datalist>';
}

function renderTagsCellHtml(recipe) {
    const chips = (recipe.tags || []).map((tag) =>
        '<span class="tag-chip d-inline-flex align-items-center gap-1">' +
        escapeHtml(tag) +
        '<button type="button" class="btn-close admin-tag-remove-btn" aria-label="' + escapeHtml(t('admin.quickeditor.remove_tag')) + '" data-recipe-id="' + recipe.id + '" data-tag="' + escapeHtml(tag) + '"></button>' +
        '</span>'
    ).join(' ');

    return (
        '<div class="d-flex flex-wrap gap-1 mb-2">' + chips + '</div>' +
        '<form class="input-group input-group-sm admin-tag-add-form" data-recipe-id="' + recipe.id + '" style="max-width:14rem">' +
        '<input type="text" class="form-control" list="adminQuickEditorSuggestions" placeholder="' + escapeHtml(t('admin.quickeditor.add_placeholder')) + '" required>' +
        '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i></button>' +
        '</form>'
    );
}

function adminRecipeDiet(recipe) {
    if (recipe.is_vegan) return 'vegan';
    if (recipe.is_vegetarian) return 'vegetarian';
    if (recipe.is_pescetarian) return 'pescetarian';

    return 'none';
}

function adminQuickSelectHtml(field, recipeId, options, current) {
    return '<select class="form-select form-select-sm qe-field" data-field="' + field + '" data-recipe-id="' + recipeId + '">' +
        options.map(([value, label]) => '<option value="' + escapeHtml(String(value)) + '"' + (String(value) === String(current) ? ' selected' : '') + '>' + escapeHtml(label) + '</option>').join('') +
        '</select>';
}

function adminQuickEditorRowHtml(recipe) {
    const id = recipe.id;
    const label = (key) => '<label class="form-label small text-muted mb-0">' + escapeHtml(t(key)) + '</label>';
    const owners = adminQuickEditorUsers.map((u) => [u.id, u.username]);
    if (!owners.some(([uid]) => uid === recipe.user_id)) {
        owners.unshift([recipe.user_id, '#' + recipe.user_id]);
    }

    return (
        '<div class="quick-edit-row border rounded p-2 mb-2" id="qeRow-' + id + '">' +
        '<div class="d-flex gap-2 align-items-center mb-2">' +
        '<input type="text" class="form-control form-control-sm fw-semibold qe-field" data-field="name" data-recipe-id="' + id + '" value="' + escapeHtml(recipe.name) + '" aria-label="' + escapeHtml(t('admin.quickeditor.name')) + '">' +
        '<a class="btn btn-sm btn-outline-secondary" href="' + siteBaseUrl() + '/#/recipes/' + id + '" target="_blank" rel="noopener" title="' + escapeHtml(t('admin.quickeditor.open')) + '"><i class="bi bi-box-arrow-up-right"></i></a>' +
        '<button type="button" class="btn btn-sm btn-outline-danger qe-delete" data-recipe-id="' + id + '" title="' + escapeHtml(t('admin.quickeditor.delete')) + '"><i class="bi bi-trash"></i></button>' +
        '</div>' +
        '<div class="row g-2 mb-2">' +
        '<div class="col-6 col-md-3">' + label('admin.quickeditor.owner') + adminQuickSelectHtml('owner_id', id, owners, recipe.user_id) + '</div>' +
        '<div class="col-6 col-md-2">' + label('admin.quickeditor.servings') +
        '<input type="number" min="1" class="form-control form-control-sm qe-field" data-field="servings" data-recipe-id="' + id + '" value="' + escapeHtml(String(recipe.servings)) + '"></div>' +
        '<div class="col-6 col-md-3">' + label('admin.quickeditor.diet') + adminQuickSelectHtml('diet', id, [
            ['none', t('admin.quickeditor.diet_none')], ['vegan', t('diet.vegan')], ['vegetarian', t('diet.vegetarian')], ['pescetarian', t('diet.pescetarian')],
        ], adminRecipeDiet(recipe)) + '</div>' +
        '<div class="col-6 col-md-4">' + label('admin.quickeditor.visibility') + adminQuickSelectHtml('visibility', id, [
            ['private', t('recipe.visibility_private')], ['internal', t('recipe.visibility_internal')], ['public', t('recipe.visibility_public')],
        ], recipe.visibility) + '</div>' +
        '</div>' +
        '<div id="adminQuickEditorCell-' + id + '">' + renderTagsCellHtml(recipe) + '</div>' +
        '</div>'
    );
}

/** The value a field currently has on the loaded recipe (for reverting). */
function adminQuickCurrentValue(recipe, field) {
    if (field === 'owner_id') return recipe.user_id;
    if (field === 'diet') return adminRecipeDiet(recipe);

    return recipe[field];
}

async function saveAdminQuickField(control) {
    const recipeId = parseInt(control.dataset.recipeId, 10);
    const field = control.dataset.field;
    const recipe = findAdminQuickEditorRecipe(recipeId);
    if (!recipe) {
        return;
    }
    let value = control.value;
    if (field === 'name') value = value.trim();
    if (field === 'servings' || field === 'owner_id') value = parseInt(value, 10);
    if (String(value) === String(adminQuickCurrentValue(recipe, field))) {
        return;
    }

    control.disabled = true;
    try {
        const updated = await Kochbuch.put('/admin/recipes/' + recipeId + '/quick', { [field]: value });
        Object.assign(recipe, {
            name: updated.name, servings: updated.servings, visibility: updated.visibility, user_id: updated.user_id,
            is_vegan: updated.is_vegan, is_vegetarian: updated.is_vegetarian, is_pescetarian: updated.is_pescetarian,
        });
        if (field === 'name') control.value = updated.name;
        showToast(t('admin.quickeditor.saved'));
    } catch (err) {
        control.value = String(adminQuickCurrentValue(recipe, field));
        showToast(translateApiError(err.data) || err.message, 'danger');
    } finally {
        control.disabled = false;
    }
}

async function deleteAdminQuickRecipe(recipeId) {
    const recipe = findAdminQuickEditorRecipe(recipeId);
    if (!recipe || !window.confirm(t('admin.quickeditor.delete_confirm', { name: recipe.name }))) {
        return;
    }
    try {
        await Kochbuch.del('/recipes/' + recipeId);
        showToast(t('admin.quickeditor.deleted'));
        loadAdminQuickEditorList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

function adminQuickEditorPaginationHtml() {
    const result = adminQuickEditorLastResult;
    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));

    return (
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
        '<div class="btn-group" role="group">' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="adminQuickEditorPagePrev"' + (result.page <= 1 ? ' disabled' : '') + '>' +
        '<i class="bi bi-chevron-left"></i> ' + escapeHtml(t('recipe.prev_page')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="adminQuickEditorPageNext"' + (result.page >= totalPages ? ' disabled' : '') + '>' +
        escapeHtml(t('recipe.next_page')) + ' <i class="bi bi-chevron-right"></i></button>' +
        '</div>' +
        '<span class="small text-muted">' + escapeHtml(t('recipe.page_of', { current: result.page, total: totalPages })) + '</span>' +
        '<div class="d-flex align-items-center gap-2">' +
        '<label class="small text-muted mb-0" for="adminQuickEditorPerPage">' + escapeHtml(t('admin.users.rows_per_page')) + '</label>' +
        '<select class="form-select form-select-sm w-auto" id="adminQuickEditorPerPage">' +
        ADMIN_LIST_PAGE_SIZES.map((size) => '<option value="' + size + '"' + (size === result.per_page ? ' selected' : '') + '>' + size + '</option>').join('') +
        '</select>' +
        '</div>' +
        '</div>'
    );
}

function renderAdminQuickEditorTable() {
    const results = document.getElementById('adminQuickEditorResults');
    const items = adminQuickEditorLastResult.items;

    if (items.length === 0) {
        results.innerHTML = '<div class="empty-state"><i class="bi bi-tags"></i><p class="mb-0">' + escapeHtml(t('recipe.none_found')) + '</p></div>';
        document.getElementById('adminQuickEditorPagination').innerHTML = '';

        return;
    }

    results.innerHTML =
        adminQuickEditorSuggestionsHtml() +
        '<div class="quick-edit-list">' + items.map(adminQuickEditorRowHtml).join('') + '</div>';

    wireAdminQuickEditorRowControls();
    results.querySelectorAll('.qe-field').forEach((control) => {
        control.addEventListener('change', () => saveAdminQuickField(control));
        if (control.tagName === 'INPUT') {
            control.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    control.blur();
                }
            });
        }
    });
    results.querySelectorAll('.qe-delete').forEach((btn) => {
        btn.addEventListener('click', () => deleteAdminQuickRecipe(parseInt(btn.dataset.recipeId, 10)));
    });

    document.getElementById('adminQuickEditorPagination').innerHTML = adminQuickEditorPaginationHtml();
    document.getElementById('adminQuickEditorPagePrev').addEventListener('click', () => {
        adminQuickEditorQuery.page = Math.max(1, adminQuickEditorLastResult.page - 1);
        loadAdminQuickEditorList();
    });
    document.getElementById('adminQuickEditorPageNext').addEventListener('click', () => {
        adminQuickEditorQuery.page = adminQuickEditorLastResult.page + 1;
        loadAdminQuickEditorList();
    });
    document.getElementById('adminQuickEditorPerPage').addEventListener('change', (e) => {
        adminQuickEditorQuery.per_page = parseInt(e.target.value, 10);
        adminQuickEditorQuery.page = 1;
        loadAdminQuickEditorList();
    });
}

function wireAdminQuickEditorRowControls() {
    document.querySelectorAll('.admin-tag-remove-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            removeAdminTag(parseInt(btn.dataset.recipeId, 10), btn.dataset.tag);
        });
    });
    document.querySelectorAll('.admin-tag-add-form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = form.querySelector('input');
            const name = input.value.trim();
            if (!name) {
                return;
            }
            addAdminTag(parseInt(form.dataset.recipeId, 10), name);
        });
    });
}

function findAdminQuickEditorRecipe(recipeId) {
    return adminQuickEditorLastResult.items.find((r) => r.id === recipeId);
}

async function applyAdminQuickEditorChange(recipeId, newTags) {
    const updated = await Kochbuch.put('/recipes/' + recipeId + '/tags', { tags: newTags });
    const recipe = findAdminQuickEditorRecipe(recipeId);
    if (recipe) {
        recipe.tags = updated.tags;
        document.getElementById('adminQuickEditorCell-' + recipeId).innerHTML = renderTagsCellHtml(recipe);
        wireAdminQuickEditorRowControls();
    }
}

async function removeAdminTag(recipeId, tagName) {
    const recipe = findAdminQuickEditorRecipe(recipeId);
    if (!recipe) {
        return;
    }
    try {
        await applyAdminQuickEditorChange(recipeId, (recipe.tags || []).filter((tag) => tag !== tagName));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function addAdminTag(recipeId, tagName) {
    const recipe = findAdminQuickEditorRecipe(recipeId);
    if (!recipe) {
        return;
    }
    try {
        await applyAdminQuickEditorChange(recipeId, (recipe.tags || []).concat([tagName]));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminQuickEditor });
