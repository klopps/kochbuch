/**
 * Recipe list / search screen (route "/" and "/recipes"). Filters live in
 * the URL's hash query string (?q=...&category_id=...&mine=1&page=1&...) so
 * the view is bookmarkable/shareable and browser back/forward works -
 * changing a filter re-navigates via Router.navigate() rather than
 * re-rendering in place, and the router's hashchange handler re-runs this
 * same function. recipe-detail.js reads lastRecipesListUrl (updated on every
 * render here) to send its back-link to the exact prior search/category/page
 * state (todo.md "Benutzeroberfläche und Suche").
 *
 * The search box is the one exception (todo.md "Search Input"): see
 * updateSearchResultsInPlace()'s own doc-comment for why its debounced
 * update deliberately bypasses Router.navigate() instead of following the
 * same pattern as every other filter.
 */
let recipesListDebounce = null;
let lastRecipesListUrl = '#/recipes';
// todo.md "Sortierung der Rezeptliste" - the sort control is a dropdown
// button, not a persistent <select> with its own .value, so there's no DOM
// element buildFilterQuery() can read the "current" sort from the way it
// reads every other filter. Tracked here instead, set on every real render
// (wireRecipesListFilters()) from the URL's query.sort/query.direction, and
// updated immediately on a dropdown item click before navigating.
let currentSortValue = null;

// Configurable via the admin "Einstellungen" page (todo.md "Admin-
// Oberfläche") - window.KOCHBUCH_SETTINGS is injected server-side from
// SettingRepository (see templates/app.php), falling back to these
// literals only if that injection is somehow missing.
const RECIPE_PAGE_SIZES = (window.KOCHBUCH_SETTINGS && window.KOCHBUCH_SETTINGS.recipe_page_sizes) || [10, 20, 100];
const RECIPE_DEFAULT_PAGE_SIZE = (window.KOCHBUCH_SETTINGS && window.KOCHBUCH_SETTINGS.recipe_default_page_size) || 10;

// Recipe list sorting - "field:direction" values, mirroring
// RecipeController::index()'s own sort/direction whitelist exactly (see
// filterRecipesOffline() for the offline-side equivalent of this logic).
// labelKey names only the field - direction is shown as an arrow icon
// (sortDirectionIcon()) rather than text ("(neueste zuerst)"/"(älteste
// zuerst)"), and the control itself is a dropdown button rather than a
// <select>, deliberately distinct from the plain filter dropdowns
// (filterSortHtml() - placed in its own right-aligned row next to the
// results count, not in the filter bar). The first entry is the
// default (today's only historical behavior, newest first) -
// buildFilterQuery() omits sort/direction from the query string entirely
// when this is what's selected, keeping the URL clean on first arrival
// (same convention as every other filter's blank/unchecked default).
const RECIPE_SORT_OPTIONS = [
    { value: 'created_at:desc', labelKey: 'recipe.sort.date', direction: 'desc' },
    { value: 'created_at:asc', labelKey: 'recipe.sort.date', direction: 'asc' },
    { value: 'name:asc', labelKey: 'recipe.sort.name', direction: 'asc' },
    { value: 'name:desc', labelKey: 'recipe.sort.name', direction: 'desc' },
    { value: 'updated_at:desc', labelKey: 'recipe.sort.updated', direction: 'desc' },
    { value: 'updated_at:asc', labelKey: 'recipe.sort.updated', direction: 'asc' },
    { value: 'rating:desc', labelKey: 'recipe.sort.rating', direction: 'desc' },
    { value: 'rating:asc', labelKey: 'recipe.sort.rating', direction: 'asc' },
];
const RECIPE_SORT_DEFAULT = RECIPE_SORT_OPTIONS[0].value;

function sortDirectionIcon(direction) {
    return '<i class="bi ' + (direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') + '"></i>';
}

async function renderRecipesList(params, query) {
    const app = document.getElementById('app');
    lastRecipesListUrl = '#/recipes' + (Object.keys(query).length ? '?' + new URLSearchParams(query).toString() : '');

    // A real navigation (initial arrival, pagination, a non-text filter
    // changing, browser back/forward, ...) rebuilds the whole skeleton via
    // app.innerHTML below, which throws away and recreates #filterQ as a
    // brand-new DOM node - that would silently drop browser focus even
    // though its value= is set correctly. The search box's own debounced
    // update no longer goes through this path at all (see
    // updateSearchResultsInPlace()), but save/restore focus/cursor state
    // here anyway as a safety net for any other path that might still
    // re-render while the field happens to be focused.
    const searchInput = document.getElementById('filterQ');
    const hadFocus = !!searchInput && document.activeElement === searchInput;
    const selectionStart = hadFocus ? searchInput.selectionStart : null;
    const selectionEnd = hadFocus ? searchInput.selectionEnd : null;

    let categories = [];
    if (Kochbuch.isLoggedIn()) {
        try {
            categories = await Kochbuch.get('/categories');
        } catch (e) {
            // todo.md "PWA/Offline Capability" - OfflineStore's own category
            // records (id/name/recipeIds, see nav.js's performFullSync())
            // are a superset of what the dropdown needs.
            categories = await OfflineStore.listCategories();
        }
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

    await renderFilteredRecipeList(query, results);
}

async function renderFilteredRecipeList(query, results) {
    const perPage = RECIPE_PAGE_SIZES.includes(Number(query.per_page)) ? Number(query.per_page) : RECIPE_DEFAULT_PAGE_SIZE;
    const page = Math.max(1, parseInt(query.page, 10) || 1);

    // todo.md "PWA/Offline Capability" - navigator.onLine is only reliable
    // for a confident "definitely offline" (airplane mode/no signal, not a
    // merely degraded connection) - skip the doomed network attempt
    // entirely in that case rather than making every keystroke in the
    // search box wait out the full request timeout before falling back.
    if (!navigator.onLine) {
        await renderFilteredRecipeListOffline(query, results, page, perPage);

        return;
    }

    try {
        const apiQuery = new URLSearchParams();
        if (query.q) apiQuery.set('q', query.q);
        if (query.difficulty) apiQuery.set('difficulty', query.difficulty);
        if (query.vegan) apiQuery.set('vegan', '1');
        if (query.vegetarian) apiQuery.set('vegetarian', '1');
        if (query.pescetarian) apiQuery.set('pescetarian', '1');
        if (query.mine) apiQuery.set('mine', '1');
        if (query.category_id) apiQuery.set('category_id', query.category_id);
        if (query.min_rating) apiQuery.set('min_rating', query.min_rating);
        if (query.sort) apiQuery.set('sort', query.sort);
        if (query.direction) apiQuery.set('direction', query.direction);
        apiQuery.set('page', String(page));
        apiQuery.set('per_page', String(perPage));

        // A short, offline-fallback-specific timeout (see offline-store.js)
        // rather than api-client.js's normal 15s default - a stalled/poor
        // connection (as opposed to a clean, instant offline failure)
        // should still give up and fall back quickly here.
        const result = await Kochbuch.get('/recipes?' + apiQuery.toString(), OFFLINE_FALLBACK_TIMEOUT_MS);
        // No count shown alongside the grid's own "Keine Rezepte gefunden."
        // empty state (recipeGridHtml()) - a "0 Rezepte gefunden" line above
        // it would just repeat the same information.
        document.getElementById('recipeResultsCount').textContent = result.total > 0 ? t('recipe.results_count', { count: result.total }) : '';
        results.innerHTML = recipeGridHtml(result.items);
        hydrateAuthImages(results);
        document.getElementById('recipePagination').innerHTML = recipePaginationHtml(result);
        wireRecipesListPagination(query);
    } catch (e) {
        await renderFilteredRecipeListOffline(query, results, page, perPage);
    }
}

/**
 * todo.md "PWA/Offline Capability" - replicates RecipeRepository::search()'s
 * filter semantics (src/Domain/Recipe/RecipeRepository.php) over the full
 * local replica (nav.js's performFullSync() normally has every recipe the
 * user can see already stored, full shape - not just a summary), so offline
 * search/filtering behaves the same as online, not a reduced free-text-only
 * fallback. No separate visibility check is needed here: OfflineStore only
 * ever contains recipes the sync's own (visibility-filtered) /recipes calls
 * already returned.
 * @return {{items: object[], total: number, page: number, per_page: number}}
 */
async function filterRecipesOffline(all, query, page, perPage) {
    let items = all;

    if (query.q) {
        const needle = foldSearchText(query.q);
        items = items.filter((r) => foldSearchText([r.name, r.description || '', ...(r.tags || [])].join(' ')).includes(needle));
    }
    if (query.difficulty) {
        items = items.filter((r) => r.difficulty === query.difficulty);
    }
    if (query.vegan) {
        items = items.filter((r) => r.is_vegan);
    }
    if (query.vegetarian) {
        items = items.filter((r) => r.is_vegetarian);
    }
    if (query.pescetarian) {
        items = items.filter((r) => r.is_pescetarian);
    }
    if (query.mine && currentUser) {
        items = items.filter((r) => r.user_id === currentUser.id);
    }
    if (query.min_rating) {
        items = items.filter((r) => r.average_rating !== null && r.average_rating >= Number(query.min_rating));
    }
    if (query.category_id && currentUser) {
        const categories = await OfflineStore.listCategories();
        if (query.category_id === 'uncategorized') {
            const inAnyCategory = new Set(categories.flatMap((c) => c.recipeIds));
            items = items.filter((r) => r.user_id === currentUser.id && !inAnyCategory.has(r.id));
        } else {
            const category = categories.find((c) => c.id === Number(query.category_id));
            const memberIds = new Set(category ? category.recipeIds : []);
            items = items.filter((r) => memberIds.has(r.id));
        }
    }

    const sorted = sortRecipesOffline(items, query.sort || 'created_at', query.direction || 'desc');
    const total = sorted.length;
    const offset = (page - 1) * perPage;

    return { items: sorted.slice(offset, offset + perPage), total, page, per_page: perPage };
}

/**
 * Mirrors RecipeRepository::search()'s ORDER BY logic (src/Domain/Recipe/
 * RecipeRepository.php) exactly, including its two special cases: unrated
 * recipes (average_rating === null) always sort last regardless of
 * direction (sorting "ascending" by rating should read as worst-to-best
 * among *rated* recipes, not put never-rated ones first), and `id` as a
 * final tiebreaker so pagination stays deterministic when many rows share
 * the same sort-key value.
 */
function sortRecipesOffline(items, sort, direction) {
    const sign = direction === 'asc' ? 1 : -1;
    const compare = (a, b) => {
        if (sort === 'rating') {
            if (a.average_rating === null && b.average_rating === null) return 0;
            if (a.average_rating === null) return 1;
            if (b.average_rating === null) return -1;

            return sign * (a.average_rating - b.average_rating);
        }
        if (sort === 'name') {
            return sign * a.name.localeCompare(b.name);
        }

        return sign * (a[sort] < b[sort] ? -1 : a[sort] > b[sort] ? 1 : 0);
    };

    return items.slice().sort((a, b) => compare(a, b) || (b.id - a.id));
}

async function renderFilteredRecipeListOffline(query, results, page, perPage) {
    const all = await OfflineStore.listRecipes();
    const result = await filterRecipesOffline(all, query, page, perPage);

    document.getElementById('recipeResultsCount').textContent = result.total > 0 ? t('recipe.results_count', { count: result.total }) : '';
    results.innerHTML =
        '<div class="alert alert-secondary py-2 small mb-3"><i class="bi bi-cloud-slash"></i> ' + escapeHtml(t('recipe.offline_list_notice')) + '</div>' +
        recipeGridHtml(result.items);
    hydrateAuthImages(results);
    document.getElementById('recipePagination').innerHTML = recipePaginationHtml(result);
    wireRecipesListPagination(query);
}

/**
 * todo.md "Search Input": typing a search term used to go through
 * navigateWithFilters()'s normal Router.navigate() -> hashchange ->
 * renderRecipesList() path like every other filter, which rebuilds the
 * entire filter skeleton (app.innerHTML in renderRecipesList()) - including
 * #filterQ itself - on every debounced keystroke pause. Even with that
 * function's focus/selection save-and-restore, destroying and recreating a
 * *focused* input is enough to flicker a mobile virtual keyboard or reset
 * IME composition state for a moment, which can swallow the very next
 * keystroke typed right after the debounce fires.
 *
 * This updates only the address bar (history.replaceState() - no
 * hashchange event, so the router never re-runs and the skeleton is never
 * rebuilt) and re-renders just the results/pagination - #filterQ is never
 * touched, so there's nothing for it to lose focus from in the first place.
 * replaceState() rather than pushState() is deliberate too: it avoids
 * spamming the browser's back-button history with one entry per keystroke,
 * which a plain `location.hash = ...` (Router.navigate()'s own mechanism)
 * would otherwise do.
 */
async function updateSearchResultsInPlace(query) {
    const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v));
    const newHash = '#/recipes' + (qs.toString() ? '?' + qs.toString() : '');
    history.replaceState(null, '', newHash);
    lastRecipesListUrl = newHash;

    const results = document.getElementById('recipeResults');
    results.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    await renderFilteredRecipeList(query, results);
}

/**
 * todo.md "Sortierung der Rezeptliste" - a dropdown *button* (Bootstrap
 * .btn.dropdown-toggle, same pattern as the "Importieren"/export menus
 * elsewhere), not a <select>, so it's visually and interactively distinct
 * from the plain filter dropdowns (filterDifficulty/filterMinRating/
 * filterCategory) - those stay native <select> form controls in the filter
 * bar above. Placed in its own row alongside #recipeResultsCount instead,
 * right-aligned (recipesListSkeleton()'s justify-content-between row) -
 * btn-sm to sit at a similar visual weight as that "small text-muted" count
 * text next to it. Direction is shown as an arrow icon per item (and on the
 * button's own current-selection label), not text.
 */
function filterSortHtml(query) {
    const current = (query.sort && query.direction) ? query.sort + ':' + query.direction : RECIPE_SORT_DEFAULT;
    const currentOption = RECIPE_SORT_OPTIONS.find((o) => o.value === current) || RECIPE_SORT_OPTIONS[0];

    return (
        '<div class="dropdown">' +
        '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" id="filterSortBtn">' +
        '<i class="bi bi-arrow-down-up"></i> ' + escapeHtml(t(currentOption.labelKey)) + ' ' + sortDirectionIcon(currentOption.direction) +
        '</button>' +
        '<ul class="dropdown-menu" id="filterSortMenu">' +
        RECIPE_SORT_OPTIONS.map((o) =>
            '<li><a class="dropdown-item' + (o.value === current ? ' active' : '') + '" href="#" data-sort-value="' + o.value + '">' +
            escapeHtml(t(o.labelKey)) + ' ' + sortDirectionIcon(o.direction) +
            '</a></li>'
        ).join('') +
        '</ul>' +
        '</div>'
    );
}

function recipesListSkeleton(query, categories) {
    return (
        '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">' +
        '<h1 class="h3 mb-0">' + escapeHtml(t('nav.recipes')) + '</h1>' +
        (Kochbuch.isLoggedIn()
            ? '<div class="d-flex flex-wrap gap-2">' +
              '<div class="dropdown">' +
              '<button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-upload"></i> ' + escapeHtml(t('recipe.import_menu')) + '</button>' +
              '<ul class="dropdown-menu">' +
              '<li><a class="dropdown-item" href="#/recipes/import-photo"><i class="bi bi-camera"></i> ' + escapeHtml(t('recipe.import_photo')) + '</a></li>' +
              '<li><a class="dropdown-item" href="#/recipes/import-json"><i class="bi bi-filetype-json"></i> ' + escapeHtml(t('recipe.import_json')) + '</a></li>' +
              '</ul></div>' +
              '<a href="#/recipes/new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.new')) + '</a>' +
              '</div>'
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
        (Kochbuch.isLoggedIn() ? filterCheckbox('filterMine', 'recipe.mine_only', query.mine) : '') +
        '<select class="form-select form-select-sm w-auto" id="filterDifficulty">' +
        '<option value="">' + escapeHtml(t('recipe.difficulty')) + '</option>' +
        ['easy', 'normal', 'hard', 'challenging'].map((d) =>
            '<option value="' + d + '"' + (query.difficulty === d ? ' selected' : '') + '>' + escapeHtml(t(DIFFICULTY_LABEL_KEY[d])) + '</option>'
        ).join('') +
        '</select>' +
        '<select class="form-select form-select-sm w-auto" id="filterMinRating">' +
        '<option value="">' + escapeHtml(t('recipe.rating.filter_label')) + '</option>' +
        [5, 4, 3, 2, 1].map((n) =>
            '<option value="' + n + '"' + (query.min_rating === String(n) ? ' selected' : '') + '>' + escapeHtml(t('recipe.rating.min_option', { count: n })) + '</option>'
        ).join('') +
        '</select>' +
        '</div>' +
        '</div>' +
        '</form>' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<div id="recipeResultsCount" class="small text-muted"></div>' +
        filterSortHtml(query) +
        '</div>' +
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
        '<option value="__create__">' + escapeHtml(t('category.create_new_option')) + '</option>' +
        '</select>' +
        '<a href="#/categories" class="btn btn-outline-secondary" title="' + escapeHtml(t('category.manage')) + '" aria-label="' + escapeHtml(t('category.manage')) + '"><i class="bi bi-gear"></i></a>' +
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

/**
 * todo.md "Paginierung verbessern" - windowed page-number list (always the
 * first/last page, a couple pages either side of the current one, "…" for
 * the gaps) rather than one <li> per page - with many pages that would
 * otherwise make the control arbitrarily wide. Standard algorithm (e.g.
 * GitHub/Google's own pagination): collect {1, total, [current-delta ..
 * current+delta]}, then walk the sorted result inserting a "…" wherever two
 * kept pages aren't adjacent.
 */
function paginationWindow(current, total, delta) {
    delta = delta || 2;
    const kept = new Set([1, total]);
    for (let p = current - delta; p <= current + delta; p++) {
        if (p >= 1 && p <= total) {
            kept.add(p);
        }
    }
    const sorted = Array.from(kept).sort((a, b) => a - b);

    const withGaps = [];
    let previous = null;
    sorted.forEach((page) => {
        if (previous !== null && page - previous > 1) {
            withGaps.push(null);
        }
        withGaps.push(page);
        previous = page;
    });

    return withGaps;
}

function recipePaginationHtml(result) {
    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));
    const current = result.page;

    const pageItem = (page, label, opts) => {
        opts = opts || {};

        return '<li class="page-item' + (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#"' + (opts.disabled ? '' : ' data-page="' + page + '"') +
            (opts.ariaLabel ? ' aria-label="' + escapeHtml(opts.ariaLabel) + '"' : '') + '>' + label + '</a>' +
            '</li>';
    };

    return (
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
        '<ul class="pagination pagination-sm mb-0" id="recipePaginationList">' +
        pageItem(current - 1, '<i class="bi bi-chevron-left"></i>', { disabled: current <= 1, ariaLabel: t('recipe.prev_page') }) +
        paginationWindow(current, totalPages).map((page) => page === null
            ? '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>'
            : pageItem(page, String(page), { active: page === current })
        ).join('') +
        pageItem(current + 1, '<i class="bi bi-chevron-right"></i>', { disabled: current >= totalPages, ariaLabel: t('recipe.next_page') }) +
        '</ul>' +
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
    // todo.md "Sortierung der Rezeptliste" - the dropdown-button sort
    // control has no persistent <select>.value of its own to read (see
    // currentSortValue's own doc-comment), so buildFilterQuery() reads this
    // instead; kept in sync with the URL on every real render.
    currentSortValue = (query.sort && query.direction) ? query.sort + ':' + query.direction : RECIPE_SORT_DEFAULT;

    // Shared by navigateWithFilters() and the search box's own in-place
    // update (updateSearchResultsInPlace()) - reads every filter's current
    // live DOM value, not just the one that just changed, so either path
    // produces the exact same query shape.
    const buildFilterQuery = (overrides) => {
        const categoryEl = document.getElementById('filterCategory');
        const mineEl = document.getElementById('filterMine');
        // Only put sort/direction in the URL when they differ from the
        // default pair - keeps the URL clean on first arrival, same as
        // filterDifficulty's/filterMinRating's blank default option.
        const sortValue = currentSortValue !== RECIPE_SORT_DEFAULT ? currentSortValue.split(':') : null;

        return {
            q: document.getElementById('filterQ').value.trim(),
            difficulty: document.getElementById('filterDifficulty').value,
            vegan: document.getElementById('filterVegan').checked ? '1' : '',
            vegetarian: document.getElementById('filterVegetarian').checked ? '1' : '',
            pescetarian: document.getElementById('filterPescetarian').checked ? '1' : '',
            mine: mineEl && mineEl.checked ? '1' : '',
            category_id: categoryEl ? categoryEl.value : '',
            min_rating: document.getElementById('filterMinRating').value,
            sort: sortValue ? sortValue[0] : '',
            direction: sortValue ? sortValue[1] : '',
            per_page: query.per_page && RECIPE_PAGE_SIZES.includes(Number(query.per_page)) ? query.per_page : '',
            page: '1',
            ...overrides,
        };
    };

    const navigateWithFilters = (overrides) => {
        const next = buildFilterQuery(overrides);
        const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v));
        Router.navigate('/recipes' + (qs.toString() ? '?' + qs.toString() : ''));
    };

    document.getElementById('recipeFilterForm').addEventListener('submit', (e) => e.preventDefault());
    document.getElementById('filterQ').addEventListener('input', () => {
        clearTimeout(recipesListDebounce);
        recipesListDebounce = setTimeout(() => updateSearchResultsInPlace(buildFilterQuery()), 350);
    });
    ['filterDifficulty', 'filterMinRating', 'filterVegan', 'filterVegetarian', 'filterPescetarian', 'filterMine'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', () => navigateWithFilters());
        }
    });

    const sortMenu = document.getElementById('filterSortMenu');
    if (sortMenu) {
        sortMenu.querySelectorAll('.dropdown-item').forEach((item) => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                currentSortValue = item.dataset.sortValue;
                navigateWithFilters();
            });
        });
    }

    const categoryEl = document.getElementById('filterCategory');
    if (categoryEl) {
        categoryEl.addEventListener('change', async () => {
            if (categoryEl.value !== '__create__') {
                navigateWithFilters();
                return;
            }
            categoryEl.value = query.category_id || '';
            const name = prompt(t('category.new_placeholder'));
            if (!name || !name.trim()) {
                return;
            }
            try {
                const category = await Kochbuch.post('/categories', { name: name.trim() });
                navigateWithFilters({ category_id: String(category.id) });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }
}

function wireRecipesListPagination(query) {
    const navigateTo = (overrides) => {
        const next = { ...query, ...overrides };
        const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v));
        Router.navigate('/recipes' + (qs.toString() ? '?' + qs.toString() : ''));
    };

    const list = document.getElementById('recipePaginationList');
    if (list) {
        list.querySelectorAll('a.page-link[data-page]').forEach((link) => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                navigateTo({ page: link.dataset.page });
            });
        });
    }
    const perPageEl = document.getElementById('filterPerPage');
    if (perPageEl) {
        perPageEl.addEventListener('change', () => navigateTo({ per_page: perPageEl.value, page: '1' }));
    }
}
