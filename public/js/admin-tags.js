/**
 * Admin "quick tag assignment" page (/admin/tags, todo.md "Schnelle
 * Tag-Zuordnung im Admin-Bereich"): a recipe list filterable the same way as
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
let adminTagsCategories = [];
let adminTagsLastResult = { items: [], total: 0, page: 1, per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE };
let adminTagsQuery = {
    q: '',
    difficulty: '',
    vegan: false,
    vegetarian: false,
    pescetarian: false,
    untagged: false,
    category_id: '',
    page: 1,
    per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE,
};
let adminTagsSearchDebounce = null;

function adminTagsSiteBaseUrl() {
    return (window.KOCHBUCH_API_BASE || '/api/v1').replace(/\/api\/v1$/, '');
}

async function initAdminTags() {
    try {
        adminTagsCategories = await Kochbuch.get('/categories');
    } catch (e) { /* filter dropdown just stays limited to "all categories" */ }

    renderAdminTagsFilters();
    loadAdminTagsList();
}

function renderAdminTagsFilters() {
    const el = document.getElementById('adminTagsFilters');

    el.innerHTML =
        '<div class="input-group mb-2">' +
        '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
        '<input type="search" class="form-control" id="adminTagsFilterQ" placeholder="' + escapeHtml(t('admin.tags.search_placeholder')) + '" value="' + escapeHtml(adminTagsQuery.q) + '">' +
        '</div>' +
        '<div class="d-flex flex-wrap gap-2 align-items-center">' +
        '<select class="form-select form-select-sm w-auto" id="adminTagsFilterCategory">' +
        '<option value="">' + escapeHtml(t('category.filter_all')) + '</option>' +
        adminTagsCategories.map((c) =>
            '<option value="' + c.id + '"' + (adminTagsQuery.category_id === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>'
        ).join('') +
        '<option value="uncategorized"' + (adminTagsQuery.category_id === 'uncategorized' ? ' selected' : '') + '>' + escapeHtml(t('category.own_uncategorized')) + '</option>' +
        '</select>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminTagsFilterVegan"' + (adminTagsQuery.vegan ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminTagsFilterVegan">' + escapeHtml(t('diet.vegan')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminTagsFilterVegetarian"' + (adminTagsQuery.vegetarian ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminTagsFilterVegetarian">' + escapeHtml(t('diet.vegetarian')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminTagsFilterPescetarian"' + (adminTagsQuery.pescetarian ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminTagsFilterPescetarian">' + escapeHtml(t('diet.pescetarian')) + '</label>' +
        '</div>' +
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="adminTagsFilterUntagged"' + (adminTagsQuery.untagged ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="adminTagsFilterUntagged">' + escapeHtml(t('admin.tags.untagged_only')) + '</label>' +
        '</div>' +
        '<select class="form-select form-select-sm w-auto" id="adminTagsFilterDifficulty">' +
        '<option value="">' + escapeHtml(t('recipe.difficulty')) + '</option>' +
        ['easy', 'normal', 'hard', 'challenging'].map((d) =>
            '<option value="' + d + '"' + (adminTagsQuery.difficulty === d ? ' selected' : '') + '>' + escapeHtml(t(DIFFICULTY_LABEL_KEY[d])) + '</option>'
        ).join('') +
        '</select>' +
        '</div>';

    document.getElementById('adminTagsFilterQ').addEventListener('input', () => {
        clearTimeout(adminTagsSearchDebounce);
        adminTagsSearchDebounce = setTimeout(() => {
            adminTagsQuery.q = document.getElementById('adminTagsFilterQ').value.trim();
            adminTagsQuery.page = 1;
            loadAdminTagsList();
        }, 350);
    });

    ['adminTagsFilterCategory', 'adminTagsFilterVegan', 'adminTagsFilterVegetarian', 'adminTagsFilterPescetarian', 'adminTagsFilterUntagged', 'adminTagsFilterDifficulty'].forEach((id) => {
        document.getElementById(id).addEventListener('change', () => {
            adminTagsQuery.category_id = document.getElementById('adminTagsFilterCategory').value;
            adminTagsQuery.vegan = document.getElementById('adminTagsFilterVegan').checked;
            adminTagsQuery.vegetarian = document.getElementById('adminTagsFilterVegetarian').checked;
            adminTagsQuery.pescetarian = document.getElementById('adminTagsFilterPescetarian').checked;
            adminTagsQuery.untagged = document.getElementById('adminTagsFilterUntagged').checked;
            adminTagsQuery.difficulty = document.getElementById('adminTagsFilterDifficulty').value;
            adminTagsQuery.page = 1;
            loadAdminTagsList();
        });
    });
}

async function loadAdminTagsList() {
    const results = document.getElementById('adminTagsResults');
    results.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    try {
        const apiQuery = new URLSearchParams();
        if (adminTagsQuery.q) apiQuery.set('q', adminTagsQuery.q);
        if (adminTagsQuery.difficulty) apiQuery.set('difficulty', adminTagsQuery.difficulty);
        if (adminTagsQuery.vegan) apiQuery.set('vegan', '1');
        if (adminTagsQuery.vegetarian) apiQuery.set('vegetarian', '1');
        if (adminTagsQuery.pescetarian) apiQuery.set('pescetarian', '1');
        if (adminTagsQuery.untagged) apiQuery.set('untagged', '1');
        if (adminTagsQuery.category_id) apiQuery.set('category_id', adminTagsQuery.category_id);
        apiQuery.set('page', String(adminTagsQuery.page));
        apiQuery.set('per_page', String(adminTagsQuery.per_page));

        adminTagsLastResult = await Kochbuch.get('/admin/recipes?' + apiQuery.toString());
        renderAdminTagsTable();
    } catch (err) {
        results.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function adminTagsSuggestionsHtml() {
    const names = new Set();
    adminTagsLastResult.items.forEach((recipe) => (recipe.tags || []).forEach((tag) => names.add(tag)));

    return '<datalist id="adminTagsSuggestions">' +
        Array.from(names).map((name) => '<option value="' + escapeHtml(name) + '">').join('') +
        '</datalist>';
}

function renderTagsCellHtml(recipe) {
    const chips = (recipe.tags || []).map((tag) =>
        '<span class="tag-chip d-inline-flex align-items-center gap-1">' +
        escapeHtml(tag) +
        '<button type="button" class="btn-close admin-tag-remove-btn" aria-label="' + escapeHtml(t('admin.tags.remove_tag')) + '" data-recipe-id="' + recipe.id + '" data-tag="' + escapeHtml(tag) + '"></button>' +
        '</span>'
    ).join(' ');

    return (
        '<div class="d-flex flex-wrap gap-1 mb-2">' + chips + '</div>' +
        '<form class="input-group input-group-sm admin-tag-add-form" data-recipe-id="' + recipe.id + '" style="max-width:14rem">' +
        '<input type="text" class="form-control" list="adminTagsSuggestions" placeholder="' + escapeHtml(t('admin.tags.add_placeholder')) + '" required>' +
        '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i></button>' +
        '</form>'
    );
}

function adminTagsRowHtml(recipe) {
    return (
        '<tr>' +
        '<td>' +
        '<a href="' + adminTagsSiteBaseUrl() + '/#/recipes/' + recipe.id + '" target="_blank" rel="noopener">' + escapeHtml(recipe.name) + '</a>' +
        '</td>' +
        '<td id="adminTagsCell-' + recipe.id + '">' + renderTagsCellHtml(recipe) + '</td>' +
        '</tr>'
    );
}

function adminTagsPaginationHtml() {
    const result = adminTagsLastResult;
    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));

    return (
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
        '<div class="btn-group" role="group">' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="adminTagsPagePrev"' + (result.page <= 1 ? ' disabled' : '') + '>' +
        '<i class="bi bi-chevron-left"></i> ' + escapeHtml(t('recipe.prev_page')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="adminTagsPageNext"' + (result.page >= totalPages ? ' disabled' : '') + '>' +
        escapeHtml(t('recipe.next_page')) + ' <i class="bi bi-chevron-right"></i></button>' +
        '</div>' +
        '<span class="small text-muted">' + escapeHtml(t('recipe.page_of', { current: result.page, total: totalPages })) + '</span>' +
        '<div class="d-flex align-items-center gap-2">' +
        '<label class="small text-muted mb-0" for="adminTagsPerPage">' + escapeHtml(t('admin.users.rows_per_page')) + '</label>' +
        '<select class="form-select form-select-sm w-auto" id="adminTagsPerPage">' +
        ADMIN_LIST_PAGE_SIZES.map((size) => '<option value="' + size + '"' + (size === result.per_page ? ' selected' : '') + '>' + size + '</option>').join('') +
        '</select>' +
        '</div>' +
        '</div>'
    );
}

function renderAdminTagsTable() {
    const results = document.getElementById('adminTagsResults');
    const items = adminTagsLastResult.items;

    if (items.length === 0) {
        results.innerHTML = '<div class="empty-state"><i class="bi bi-tags"></i><p class="mb-0">' + escapeHtml(t('recipe.none_found')) + '</p></div>';
        document.getElementById('adminTagsPagination').innerHTML = '';

        return;
    }

    results.innerHTML =
        adminTagsSuggestionsHtml() +
        '<div class="table-responsive"><table class="table align-middle">' +
        '<thead><tr><th>' + escapeHtml(t('admin.tags.col_recipe')) + '</th><th>' + escapeHtml(t('admin.tags.col_tags')) + '</th></tr></thead>' +
        '<tbody>' + items.map(adminTagsRowHtml).join('') + '</tbody>' +
        '</table></div>';

    wireAdminTagsRowControls();

    document.getElementById('adminTagsPagination').innerHTML = adminTagsPaginationHtml();
    document.getElementById('adminTagsPagePrev').addEventListener('click', () => {
        adminTagsQuery.page = Math.max(1, adminTagsLastResult.page - 1);
        loadAdminTagsList();
    });
    document.getElementById('adminTagsPageNext').addEventListener('click', () => {
        adminTagsQuery.page = adminTagsLastResult.page + 1;
        loadAdminTagsList();
    });
    document.getElementById('adminTagsPerPage').addEventListener('change', (e) => {
        adminTagsQuery.per_page = parseInt(e.target.value, 10);
        adminTagsQuery.page = 1;
        loadAdminTagsList();
    });
}

function wireAdminTagsRowControls() {
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

function findAdminTagsRecipe(recipeId) {
    return adminTagsLastResult.items.find((r) => r.id === recipeId);
}

async function applyAdminTagsChange(recipeId, newTags) {
    const updated = await Kochbuch.put('/recipes/' + recipeId + '/tags', { tags: newTags });
    const recipe = findAdminTagsRecipe(recipeId);
    if (recipe) {
        recipe.tags = updated.tags;
        document.getElementById('adminTagsCell-' + recipeId).innerHTML = renderTagsCellHtml(recipe);
        wireAdminTagsRowControls();
    }
}

async function removeAdminTag(recipeId, tagName) {
    const recipe = findAdminTagsRecipe(recipeId);
    if (!recipe) {
        return;
    }
    try {
        await applyAdminTagsChange(recipeId, (recipe.tags || []).filter((tag) => tag !== tagName));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function addAdminTag(recipeId, tagName) {
    const recipe = findAdminTagsRecipe(recipeId);
    if (!recipe) {
        return;
    }
    try {
        await applyAdminTagsChange(recipeId, (recipe.tags || []).concat([tagName]));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminTags });
