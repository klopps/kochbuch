/**
 * "My categories" screens (routes "/categories" and "/categories/:id") -
 * every user's own private grouping of recipes (their own or public ones
 * from others), see todo.md's "Kategorien" section.
 */
async function renderCategories() {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML =
        '<div class="d-flex justify-content-between align-items-center mb-3">' +
        '<h1 class="h3 mb-0">' + escapeHtml(t('nav.categories')) + '</h1>' +
        '</div>' +
        '<form id="newCategoryForm" class="input-group mb-4" style="max-width:28rem">' +
        '<input type="text" class="form-control" id="newCategoryName" placeholder="' + escapeHtml(t('category.new_placeholder')) + '" required>' +
        '<button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i></button>' +
        '</form>' +
        '<div id="categoryList"></div>';

    document.getElementById('newCategoryForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = document.getElementById('newCategoryName');
        if (!input.value.trim()) {
            return;
        }
        try {
            await Kochbuch.post('/categories', { name: input.value.trim() });
            input.value = '';
            loadCategoryList();
        } catch (err) {
            showToast(translateApiError(err.data) || err.message, 'danger');
        }
    });

    loadCategoryList();
}

async function loadCategoryList() {
    const list = document.getElementById('categoryList');
    list.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        const categories = await Kochbuch.get('/categories');
        list.innerHTML = categories.length
            ? '<div class="list-group">' + categories.map(categoryRowHtml).join('') + '</div>'
            : '<div class="empty-state"><i class="bi bi-collection"></i><p class="mb-0">' + escapeHtml(t('category.empty')) + '</p></div>';
    } catch (err) {
        list.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function categoryRowHtml(category) {
    return (
        '<a href="#/categories/' + category.id + '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">' +
        '<span><i class="bi bi-collection me-2"></i>' + escapeHtml(category.name) + '</span>' +
        '<span class="badge text-bg-secondary rounded-pill">' + category.recipe_count + '</span>' +
        '</a>'
    );
}

async function renderCategoryDetail(params) {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    let category;
    let categories;
    try {
        categories = await Kochbuch.get('/categories');
        category = categories.find((c) => String(c.id) === params.id);
        if (!category) {
            throw new Error(t('category.not_found'));
        }
    } catch (e) {
        app.innerHTML = '<div class="alert alert-danger">' + escapeHtml(e.message) + '</div>';

        return;
    }

    app.innerHTML =
        '<div class="mb-3"><a href="#/categories" class="link-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> ' + escapeHtml(t('nav.categories')) + '</a></div>' +
        '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">' +
        '<h1 class="h3 mb-0" id="categoryTitle">' + escapeHtml(category.name) + '</h1>' +
        '<div class="d-flex gap-2">' +
        '<button type="button" class="btn btn-outline-secondary" id="renameCategoryBtn"><i class="bi bi-pencil"></i></button>' +
        '<button type="button" class="btn btn-outline-danger" id="deleteCategoryBtn"><i class="bi bi-trash"></i></button>' +
        '</div></div>' +
        '<div id="categoryRecipes"></div>';

    document.getElementById('renameCategoryBtn').addEventListener('click', async () => {
        const name = prompt(t('category.rename_prompt'), category.name);
        if (!name || !name.trim()) {
            return;
        }
        try {
            await Kochbuch.put('/categories/' + category.id, { name: name.trim() });
            document.getElementById('categoryTitle').textContent = name.trim();
        } catch (err) {
            showToast(translateApiError(err.data) || err.message, 'danger');
        }
    });

    document.getElementById('deleteCategoryBtn').addEventListener('click', async () => {
        if (!await showConfirmDialog(t('category.delete_confirm'), { type: 'danger' })) {
            return;
        }
        try {
            await Kochbuch.del('/categories/' + category.id);
            Router.navigate('/categories');
        } catch (err) {
            showToast(translateApiError(err.data) || err.message, 'danger');
        }
    });

    const recipesBox = document.getElementById('categoryRecipes');
    try {
        const recipes = await Kochbuch.get('/categories/' + category.id + '/recipes');
        recipesBox.innerHTML = recipeGridHtml(recipes);
        hydrateAuthImages(recipesBox);
    } catch (err) {
        recipesBox.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}
