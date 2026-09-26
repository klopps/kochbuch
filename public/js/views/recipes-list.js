/**
 * Recipe list / search screen (route "/" and "/recipes"). Filters live in
 * the URL's hash query string (?q=...&category_id=...&mine=1&page=1&...) so
 * the view is bookmarkable/shareable and browser back/forward works -
 * changing a filter re-navigates via Router.navigate() rather than
 * re-rendering in place, and the router's hashchange handler re-runs this
 * same function. recipe-detail.js reads lastRecipesListUrl (updated on every
 * render here) to send its back-link to the exact prior search/category/page
 * state (todo.md "Benutzeroberfläche und Suche").
 */
let recipesListDebounce = null;
let lastRecipesListUrl = '#/recipes';

// Configurable via the admin "Einstellungen" page (todo.md "Admin-
// Oberfläche") - window.KOCHBUCH_SETTINGS is injected server-side from
// SettingRepository (see templates/app.php), falling back to these
// literals only if that injection is somehow missing.
const RECIPE_PAGE_SIZES = (window.KOCHBUCH_SETTINGS && window.KOCHBUCH_SETTINGS.recipe_page_sizes) || [10, 20, 100];
const RECIPE_DEFAULT_PAGE_SIZE = (window.KOCHBUCH_SETTINGS && window.KOCHBUCH_SETTINGS.recipe_default_page_size) || 10;

async function renderRecipesList(params, query) {
    const app = document.getElementById('app');
    lastRecipesListUrl = '#/recipes' + (Object.keys(query).length ? '?' + new URLSearchParams(query).toString() : '');

    // The debounced search input triggers Router.navigate() on every
    // keystroke pause, which re-runs this whole function - app.innerHTML
    // below throws away and recreates #filterQ as a brand-new DOM node,
    // which silently drops browser focus even though its value= is set
    // correctly (todo.md: "Suche verliert Fokus"). Save focus/cursor state
    // before replacing the DOM and restore it after, only for this input.
    const searchInput = document.getElementById('filterQ');
    const hadFocus = !!searchInput && document.activeElement === searchInput;
    const selectionStart = hadFocus ? searchInput.selectionStart : null;
    const selectionEnd = hadFocus ? searchInput.selectionEnd : null;

    let categories = [];
    if (Kochbuch.isLoggedIn()) {
        try {
            categories = await Kochbuch.get('/categories');
        } catch (e) { /* filter dropdown just stays limited to "all" */ }
    }

    app.innerHTML = recipesListSkeleton(query, categories);
    wireRecipesListFilters(query);

    if (hadFocus) {
        const newSearchInput = document.getElementById('filterQ');
        newSearchInput.focus();
        newSearchInput.setSelectionRange(selectionStart, selectionEnd);
    }

    const results = document.getElementById('recipeResults');
    results.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    const perPage = RECIPE_PAGE_SIZES.includes(Number(query.per_page)) ? Number(query.per_page) : RECIPE_DEFAULT_PAGE_SIZE;
    const page = Math.max(1, parseInt(query.page, 10) || 1);

    try {
        const apiQuery = new URLSearchParams();
        if (query.q) apiQuery.set('q', query.q);
        if (query.difficulty) apiQuery.set('difficulty', query.difficulty);
        if (query.vegan) apiQuery.set('vegan', '1');
        if (query.vegetarian) apiQuery.set('vegetarian', '1');
        if (query.pescetarian) apiQuery.set('pescetarian', '1');
        if (query.mine) apiQuery.set('mine', '1');
        if (query.category_id) apiQuery.set('category_id', query.category_id);
        apiQuery.set('page', String(page));
        apiQuery.set('per_page', String(perPage));

        const result = await Kochbuch.get('/recipes?' + apiQuery.toString());
        results.innerHTML = recipeGridHtml(result.items);
        hydrateAuthImages(results);
        document.getElementById('recipePagination').innerHTML = recipePaginationHtml(result);
        wireRecipesListPagination(query);
    } catch (e) {
        results.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(e.data) || e.message) + '</div>';
    }
}

function recipesListSkeleton(query, categories) {
    return (
        '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">' +
        '<h1 class="h3 mb-0">' + escapeHtml(t('nav.recipes')) + '</h1>' +
        (Kochbuch.isLoggedIn()
            ? '<a href="#/recipes/new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.new')) + '</a>'
            : '') +
        '</div>' +
        '<form id="recipeFilterForm" class="mb-3">' +
        '<div class="input-group mb-2">' +
        '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
        '<input type="search" class="form-control" id="filterQ" placeholder="' + escapeHtml(t('nav.search')) + '" value="' + escapeHtml(query.q || '') + '">' +
        '</div>' +
        (Kochbuch.isLoggedIn() ? recipeCategoryFilterHtml(query, categories) : '') +
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">' +
        '<div class="d-flex flex-wrap gap-3 align-items-center">' +
        filterCheckbox('filterVegan', 'diet.vegan', query.vegan) +
        filterCheckbox('filterVegetarian', 'diet.vegetarian', query.vegetarian) +
        filterCheckbox('filterPescetarian', 'diet.pescetarian', query.pescetarian) +
        '<select class="form-select form-select-sm w-auto" id="filterDifficulty">' +
        '<option value="">' + escapeHtml(t('recipe.difficulty')) + '</option>' +
        ['easy', 'normal', 'hard', 'challenging'].map((d) =>
            '<option value="' + d + '"' + (query.difficulty === d ? ' selected' : '') + '>' + escapeHtml(t(DIFFICULTY_LABEL_KEY[d])) + '</option>'
        ).join('') +
        '</select>' +
        '</div>' +
        (Kochbuch.isLoggedIn() ? recipeMineOnlyPillSwitchHtml(query) : '') +
        '</div>' +
        '</form>' +
        '<div id="recipeResults"></div>' +
        '<nav id="recipePagination" class="mt-3"></nav>'
    );
}

function recipeCategoryFilterHtml(query, categories) {
    const selected = query.category_id || '';

    return (
        // Wrapped in an input-group with a link to #/categories: the navbar no
        // longer has a "Kategorien" entry, so this is where users get to
        // create/rename/delete their own categories.
        '<div class="input-group">' +
        '<select class="form-select mb-0" id="filterCategory" aria-label="' + escapeHtml(t('category.filter_label')) + '">' +
        '<option value="">' + escapeHtml(t('category.filter_all')) + '</option>' +
        categories.map((c) =>
            '<option value="' + c.id + '"' + (selected === String(c.id) ? ' selected' : '') + '>' + escapeHtml(c.name) + '</option>'
        ).join('') +
        '<option value="uncategorized"' + (selected === 'uncategorized' ? ' selected' : '') + '>' + escapeHtml(t('category.own_uncategorized')) + '</option>' +
        '</select>' +
        '<a href="#/categories" class="btn btn-outline-secondary" title="' + escapeHtml(t('category.manage')) + '" aria-label="' + escapeHtml(t('category.manage')) + '"><i class="bi bi-gear"></i></a>' +
        '</div>'
    );
}

function recipeMineOnlyPillSwitchHtml(query) {
    return (
        '<div class="form-check form-switch mine-only-switch m-0">' +
        '<input class="form-check-input" type="checkbox" role="switch" id="filterMine"' + (query.mine ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="filterMine">' + escapeHtml(t('recipe.mine_only')) + '</label>' +
        '</div>'
    );
}

function filterCheckbox(id, labelKey, checked) {
    return (
        '<div class="form-check form-check-inline m-0">' +
        '<input class="form-check-input" type="checkbox" id="' + id + '"' + (checked ? ' checked' : '') + '>' +
        '<label class="form-check-label small" for="' + id + '">' + escapeHtml(t(labelKey)) + '</label>' +
        '</div>'
    );
}

function recipePaginationHtml(result) {
    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));

    return (
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
        '<div class="btn-group" role="group">' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="paginationPrev"' + (result.page <= 1 ? ' disabled' : '') + '>' +
        '<i class="bi bi-chevron-left"></i> ' + escapeHtml(t('recipe.prev_page')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="paginationNext"' + (result.page >= totalPages ? ' disabled' : '') + '>' +
        escapeHtml(t('recipe.next_page')) + ' <i class="bi bi-chevron-right"></i></button>' +
        '</div>' +
        '<span class="small text-muted">' + escapeHtml(t('recipe.page_of', { current: result.page, total: totalPages })) + '</span>' +
        '<div class="d-flex align-items-center gap-2">' +
        '<label class="small text-muted mb-0" for="filterPerPage">' + escapeHtml(t('recipe.per_page')) + '</label>' +
        '<select class="form-select form-select-sm w-auto" id="filterPerPage">' +
        RECIPE_PAGE_SIZES.map((size) => '<option value="' + size + '"' + (size === result.per_page ? ' selected' : '') + '>' + size + '</option>').join('') +
        '</select>' +
        '</div>' +
        '</div>'
    );
}

function wireRecipesListFilters(query) {
    const navigateWithFilters = (overrides) => {
        const categoryEl = document.getElementById('filterCategory');
        const mineEl = document.getElementById('filterMine');
        const next = {
            q: document.getElementById('filterQ').value.trim(),
            difficulty: document.getElementById('filterDifficulty').value,
            vegan: document.getElementById('filterVegan').checked ? '1' : '',
            vegetarian: document.getElementById('filterVegetarian').checked ? '1' : '',
            pescetarian: document.getElementById('filterPescetarian').checked ? '1' : '',
            mine: mineEl && mineEl.checked ? '1' : '',
            category_id: categoryEl ? categoryEl.value : '',
            per_page: query.per_page && RECIPE_PAGE_SIZES.includes(Number(query.per_page)) ? query.per_page : '',
            page: '1',
            ...overrides,
        };
        const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v));
        Router.navigate('/recipes' + (qs.toString() ? '?' + qs.toString() : ''));
    };

    document.getElementById('recipeFilterForm').addEventListener('submit', (e) => e.preventDefault());
    document.getElementById('filterQ').addEventListener('input', () => {
        clearTimeout(recipesListDebounce);
        recipesListDebounce = setTimeout(() => navigateWithFilters(), 350);
    });
    ['filterDifficulty', 'filterVegan', 'filterVegetarian', 'filterPescetarian', 'filterMine', 'filterCategory'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', () => navigateWithFilters());
        }
    });
}

function wireRecipesListPagination(query) {
    const navigateTo = (overrides) => {
        const next = { ...query, ...overrides };
        const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v));
        Router.navigate('/recipes' + (qs.toString() ? '?' + qs.toString() : ''));
    };

    const currentPage = Math.max(1, parseInt(query.page, 10) || 1);
    const prevBtn = document.getElementById('paginationPrev');
    const nextBtn = document.getElementById('paginationNext');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => navigateTo({ page: String(currentPage - 1) }));
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => navigateTo({ page: String(currentPage + 1) }));
    }
    const perPageEl = document.getElementById('filterPerPage');
    if (perPageEl) {
        perPageEl.addEventListener('change', () => navigateTo({ per_page: perPageEl.value, page: '1' }));
    }
}
