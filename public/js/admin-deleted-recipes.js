/**
 * Admin "Papierkorb" (trash) for soft-deleted recipes (todo.md "Deleting
 * Recipes") - a small, hand-managed list (no pagination, same reasoning as
 * admin-placeholder-images.js: deleted recipes are expected to stay few).
 * Each row offers "Wiederherstellen" (restore) or "Endgültig löschen"
 * (permanent delete, confirm first - window.confirm(), same convention as
 * admin-placeholder-images.js's deletePlaceholderImage()).
 */
let adminDeletedRecipesLastList = [];

async function initAdminDeletedRecipes() {
    loadDeletedRecipesList();
}

async function loadDeletedRecipesList() {
    const list = document.getElementById('deletedRecipesList');
    list.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        adminDeletedRecipesLastList = await Kochbuch.get('/admin/recipes/deleted');
        renderDeletedRecipesList();
    } catch (err) {
        list.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function renderDeletedRecipesList() {
    const list = document.getElementById('deletedRecipesList');

    if (adminDeletedRecipesLastList.length === 0) {
        list.innerHTML = '<div class="empty-state"><i class="bi bi-trash"></i><p class="mb-0">' + escapeHtml(t('admin.deleted_recipes.empty')) + '</p></div>';

        return;
    }

    list.innerHTML =
        '<div class="table-responsive"><table class="table align-middle">' +
        '<thead><tr>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_name')) + '</th>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_owner')) + '</th>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_deleted_at')) + '</th>' +
        '<th class="text-end"></th>' +
        '</tr></thead>' +
        '<tbody>' + adminDeletedRecipesLastList.map(deletedRecipeRowHtml).join('') + '</tbody>' +
        '</table></div>';

    list.querySelectorAll('.deleted-recipe-restore-btn').forEach((btn) => {
        btn.addEventListener('click', () => restoreDeletedRecipe(parseInt(btn.dataset.id, 10)));
    });
    list.querySelectorAll('.deleted-recipe-permanent-btn').forEach((btn) => {
        btn.addEventListener('click', () => permanentlyDeleteRecipe(parseInt(btn.dataset.id, 10)));
    });
}

function deletedRecipeRowHtml(row) {
    return (
        '<tr>' +
        '<td>' + escapeHtml(row.name) +
        (VISIBILITY_META[row.visibility] ? ' <span class="badge text-bg-secondary"><i class="bi ' + VISIBILITY_META[row.visibility].icon + '"></i> ' + escapeHtml(t(VISIBILITY_META[row.visibility].labelKey)) + '</span>' : '') +
        '</td>' +
        '<td>' + escapeHtml(row.owner_username || '–') + '</td>' +
        '<td>' + escapeHtml(new Date(row.deleted_at).toLocaleString(window.KOCHBUCH_LOCALE)) + '</td>' +
        '<td class="text-end">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary deleted-recipe-restore-btn" data-id="' + row.id + '" title="' + escapeHtml(t('admin.deleted_recipes.restore')) + '"><i class="bi bi-arrow-counterclockwise"></i></button> ' +
        '<button type="button" class="btn btn-sm btn-outline-danger deleted-recipe-permanent-btn" data-id="' + row.id + '" title="' + escapeHtml(t('admin.deleted_recipes.permanent_delete')) + '"><i class="bi bi-trash3"></i></button>' +
        '</td>' +
        '</tr>'
    );
}

async function restoreDeletedRecipe(id) {
    try {
        await Kochbuch.put('/admin/recipes/' + id + '/restore', {});
        showToast(t('admin.deleted_recipes.restored'));
        loadDeletedRecipesList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function permanentlyDeleteRecipe(id) {
    if (!window.confirm(t('admin.deleted_recipes.permanent_delete_confirm'))) {
        return;
    }
    try {
        await Kochbuch.del('/admin/recipes/' + id + '/permanent');
        showToast(t('admin.deleted_recipes.permanently_deleted'));
        loadDeletedRecipesList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminDeletedRecipes });
