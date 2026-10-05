/**
 * Admin "Papierkorb" (trash) for soft-deleted recipes (todo.md "Deleting
 * Recipes"), paginated like the quick editor (ADMIN_LIST_PAGE_SIZES). Each
 * row offers "Wiederherstellen" (restore) or "Endgültig löschen" (permanent
 * delete); several rows can be ticked and deleted together. The selection
 * is a Set of recipe ids that survives page changes - "Alle" fills it from
 * the server's all_ids (every recipe in the trash, not just the visible
 * page), "Keine" empties it. Confirmation via window.confirm(), same
 * convention as admin-placeholder-images.js's deletePlaceholderImage().
 */
let adminDeletedRecipesLastResult = { items: [], total: 0, page: 1, per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE, all_ids: [] };
let adminDeletedRecipesQuery = { page: 1, per_page: ADMIN_LIST_DEFAULT_PAGE_SIZE };
const adminDeletedRecipesSelected = new Set();

async function initAdminDeletedRecipes() {
    loadDeletedRecipesList();
}

async function loadDeletedRecipesList() {
    const list = document.getElementById('deletedRecipesList');
    list.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        adminDeletedRecipesLastResult = await Kochbuch.get('/admin/recipes/deleted?page=' + adminDeletedRecipesQuery.page + '&per_page=' + adminDeletedRecipesQuery.per_page);
        // Drop ids that are no longer in the trash (restored/deleted elsewhere).
        const stillThere = new Set(adminDeletedRecipesLastResult.all_ids);
        [...adminDeletedRecipesSelected].forEach((id) => {
            if (!stillThere.has(id)) {
                adminDeletedRecipesSelected.delete(id);
            }
        });
        renderDeletedRecipesList();
    } catch (err) {
        list.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function renderDeletedRecipesList() {
    const list = document.getElementById('deletedRecipesList');
    const result = adminDeletedRecipesLastResult;

    if (result.total === 0) {
        list.innerHTML = '<div class="empty-state"><i class="bi bi-trash"></i><p class="mb-0">' + escapeHtml(t('admin.deleted_recipes.empty')) + '</p></div>';

        return;
    }

    list.innerHTML =
        '<div class="d-flex flex-wrap align-items-center gap-2 mb-3">' +
        '<div class="btn-group" role="group">' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="deletedRecipesSelectAll"><i class="bi bi-check2-all"></i> ' + escapeHtml(t('admin.deleted_recipes.select_all')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="deletedRecipesSelectNone"><i class="bi bi-x-lg"></i> ' + escapeHtml(t('admin.deleted_recipes.select_none')) + '</button>' +
        '</div>' +
        '<button type="button" class="btn btn-danger btn-sm" id="deletedRecipesBulkDelete"></button>' +
        '<span class="small text-muted ms-auto">' + escapeHtml(t('admin.deleted_recipes.total', { count: result.total })) + '</span>' +
        '</div>' +
        '<div class="table-responsive"><table class="table align-middle">' +
        '<thead><tr>' +
        '<th class="text-center" style="width:2.5rem"><input class="form-check-input" type="checkbox" id="deletedRecipesPageCheck" title="' + escapeHtml(t('admin.deleted_recipes.select_page')) + '"></th>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_name')) + '</th>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_owner')) + '</th>' +
        '<th>' + escapeHtml(t('admin.deleted_recipes.col_deleted_at')) + '</th>' +
        '<th class="text-end"></th>' +
        '</tr></thead>' +
        '<tbody>' + result.items.map(deletedRecipeRowHtml).join('') + '</tbody>' +
        '</table></div>' +
        deletedRecipesPaginationHtml();

    list.querySelectorAll('.deleted-recipe-check').forEach((box) => {
        box.addEventListener('change', () => {
            const id = parseInt(box.dataset.id, 10);
            if (box.checked) {
                adminDeletedRecipesSelected.add(id);
            } else {
                adminDeletedRecipesSelected.delete(id);
            }
            updateDeletedRecipesSelectionUi();
        });
    });
    document.getElementById('deletedRecipesPageCheck').addEventListener('change', (e) => {
        result.items.forEach((row) => {
            if (e.target.checked) {
                adminDeletedRecipesSelected.add(row.id);
            } else {
                adminDeletedRecipesSelected.delete(row.id);
            }
        });
        syncDeletedRecipesCheckboxes();
    });
    document.getElementById('deletedRecipesSelectAll').addEventListener('click', () => {
        result.all_ids.forEach((id) => adminDeletedRecipesSelected.add(id));
        syncDeletedRecipesCheckboxes();
    });
    document.getElementById('deletedRecipesSelectNone').addEventListener('click', () => {
        adminDeletedRecipesSelected.clear();
        syncDeletedRecipesCheckboxes();
    });
    document.getElementById('deletedRecipesBulkDelete').addEventListener('click', bulkPermanentlyDeleteRecipes);

    list.querySelectorAll('.deleted-recipe-restore-btn').forEach((btn) => {
        btn.addEventListener('click', () => restoreDeletedRecipe(parseInt(btn.dataset.id, 10)));
    });
    list.querySelectorAll('.deleted-recipe-permanent-btn').forEach((btn) => {
        btn.addEventListener('click', () => permanentlyDeleteRecipe(parseInt(btn.dataset.id, 10)));
    });

    document.getElementById('deletedRecipesPagePrev').addEventListener('click', () => {
        adminDeletedRecipesQuery.page = Math.max(1, result.page - 1);
        loadDeletedRecipesList();
    });
    document.getElementById('deletedRecipesPageNext').addEventListener('click', () => {
        adminDeletedRecipesQuery.page = result.page + 1;
        loadDeletedRecipesList();
    });
    document.getElementById('deletedRecipesPerPage').addEventListener('change', (e) => {
        adminDeletedRecipesQuery.per_page = parseInt(e.target.value, 10);
        adminDeletedRecipesQuery.page = 1;
        loadDeletedRecipesList();
    });

    updateDeletedRecipesSelectionUi();
}

function syncDeletedRecipesCheckboxes() {
    document.querySelectorAll('.deleted-recipe-check').forEach((box) => {
        box.checked = adminDeletedRecipesSelected.has(parseInt(box.dataset.id, 10));
    });
    updateDeletedRecipesSelectionUi();
}

function updateDeletedRecipesSelectionUi() {
    const count = adminDeletedRecipesSelected.size;
    const bulk = document.getElementById('deletedRecipesBulkDelete');
    bulk.disabled = count === 0;
    bulk.innerHTML = '<i class="bi bi-trash3"></i> ' + escapeHtml(t('admin.deleted_recipes.delete_selected', { count: count }));

    const items = adminDeletedRecipesLastResult.items;
    const onPage = items.filter((row) => adminDeletedRecipesSelected.has(row.id)).length;
    const pageCheck = document.getElementById('deletedRecipesPageCheck');
    pageCheck.checked = items.length > 0 && onPage === items.length;
    pageCheck.indeterminate = onPage > 0 && onPage < items.length;
}

function deletedRecipesPaginationHtml() {
    const result = adminDeletedRecipesLastResult;
    const totalPages = Math.max(1, Math.ceil(result.total / result.per_page));

    return (
        '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' +
        '<div class="btn-group" role="group">' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="deletedRecipesPagePrev"' + (result.page <= 1 ? ' disabled' : '') + '>' +
        '<i class="bi bi-chevron-left"></i> ' + escapeHtml(t('recipe.prev_page')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="deletedRecipesPageNext"' + (result.page >= totalPages ? ' disabled' : '') + '>' +
        escapeHtml(t('recipe.next_page')) + ' <i class="bi bi-chevron-right"></i></button>' +
        '</div>' +
        '<span class="small text-muted">' + escapeHtml(t('recipe.page_of', { current: result.page, total: totalPages })) + '</span>' +
        '<div class="d-flex align-items-center gap-2">' +
        '<label class="small text-muted mb-0" for="deletedRecipesPerPage">' + escapeHtml(t('admin.users.rows_per_page')) + '</label>' +
        '<select class="form-select form-select-sm w-auto" id="deletedRecipesPerPage">' +
        ADMIN_LIST_PAGE_SIZES.map((size) => '<option value="' + size + '"' + (size === result.per_page ? ' selected' : '') + '>' + size + '</option>').join('') +
        '</select>' +
        '</div>' +
        '</div>'
    );
}

function deletedRecipeRowHtml(row) {
    return (
        '<tr>' +
        '<td class="text-center"><input class="form-check-input deleted-recipe-check" type="checkbox" data-id="' + row.id + '"' + (adminDeletedRecipesSelected.has(row.id) ? ' checked' : '') + ' aria-label="' + escapeHtml(row.name) + '"></td>' +
        '<td>' + escapeHtml(row.name) +
        (VISIBILITY_META[row.visibility] ? ' <span class="badge text-bg-secondary"><i class="bi ' + VISIBILITY_META[row.visibility].icon + '"></i> ' + escapeHtml(t(VISIBILITY_META[row.visibility].labelKey)) + '</span>' : '') +
        '</td>' +
        '<td>' + escapeHtml(row.owner_username || '–') + '</td>' +
        '<td>' + escapeHtml(new Date(row.deleted_at).toLocaleString(window.KOCHBUCH_LOCALE)) + '</td>' +
        '<td class="text-end text-nowrap">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary deleted-recipe-restore-btn" data-id="' + row.id + '" title="' + escapeHtml(t('admin.deleted_recipes.restore')) + '"><i class="bi bi-arrow-counterclockwise"></i></button> ' +
        '<button type="button" class="btn btn-sm btn-outline-danger deleted-recipe-permanent-btn" data-id="' + row.id + '" title="' + escapeHtml(t('admin.deleted_recipes.permanent_delete')) + '"><i class="bi bi-trash3"></i></button>' +
        '</td>' +
        '</tr>'
    );
}

async function restoreDeletedRecipe(id) {
    try {
        await Kochbuch.put('/admin/recipes/' + id + '/restore', {});
        adminDeletedRecipesSelected.delete(id);
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
        adminDeletedRecipesSelected.delete(id);
        showToast(t('admin.deleted_recipes.permanently_deleted'));
        loadDeletedRecipesList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function bulkPermanentlyDeleteRecipes() {
    const ids = [...adminDeletedRecipesSelected];
    if (ids.length === 0 || !window.confirm(t('admin.deleted_recipes.delete_selected_confirm', { count: ids.length }))) {
        return;
    }
    const bulk = document.getElementById('deletedRecipesBulkDelete');
    bulk.disabled = true;
    try {
        const result = await Kochbuch.post('/admin/recipes/permanent-delete', { ids: ids });
        adminDeletedRecipesSelected.clear();
        showToast(t('admin.deleted_recipes.deleted_selected', { count: result.deleted }));
        loadDeletedRecipesList();
    } catch (err) {
        bulk.disabled = false;
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminDeletedRecipes });
