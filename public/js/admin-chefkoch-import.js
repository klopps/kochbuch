/**
 * Chefkoch.de importer (todo.md "Import aus Kochbuch von Chefkoch.de") -
 * authenticates with a pasted x-chefkoch-api-token (there is no
 * username/password login, see ChefkochImportService's own doc-comment for
 * why), lists the admin's personal Chefkoch "Kochbuch", flags recipes
 * already imported by source_url, and lets the admin selectively import the
 * checked ones. The token is kept only in this module-level variable for as
 * long as the page stays open - never written to localStorage/
 * sessionStorage, never sent anywhere except the two chefkoch-import API
 * calls below (todo.md: "nicht dauerhaft in der Datenbank speichern").
 */
let chefkochAuth = null;
let chefkochFoundRecipes = [];

// Both chefkoch-import endpoints make several sequential upstream requests
// to chefkoch.de server-side before responding (listing pages through the
// admin's whole "Mein Kochbuch" 12 recipes at a time; importing fetches and
// downloads an image per selected recipe) - api-client.js's normal default
// timeout would abort a large one of these long before it has a chance to
// finish.
const CHEFKOCH_TIMEOUT_MS = 180000;

function initAdminChefkochImport() {
    renderChefkochLoginForm();
}

function renderChefkochLoginForm() {
    const container = document.getElementById('chefkochImportLoginForm');

    container.innerHTML =
        '<h2 class="h5 mb-3">' + escapeHtml(t('admin.chefkoch_import.login_heading')) + '</h2>' +
        '<form id="chefkochLoginForm" style="max-width:32rem">' +
        '<div class="mb-2">' +
        '<label class="form-label">' + escapeHtml(t('admin.chefkoch_import.token_label')) + '</label>' +
        '<input type="text" class="form-control" id="chefkochToken" autocomplete="off">' +
        '<div class="form-text">' + escapeHtml(t('admin.chefkoch_import.token_help')) + '</div>' +
        '</div>' +
        '<button type="submit" class="btn btn-primary" id="chefkochListBtn">' + escapeHtml(t('admin.chefkoch_import.list_button')) + '</button>' +
        '</form>';

    document.getElementById('chefkochLoginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const credentials = { token: document.getElementById('chefkochToken').value.trim() };

        // todo.md "Loading Indicator During Longer Processes" - listing a
        // large Chefkoch "Mein Kochbuch" genuinely can take several seconds
        // (it pages through the real API 12 recipes at a time).
        await withBusyButton(e.submitter, async () => {
            const data = await Kochbuch.post('/admin/chefkoch-import/list', credentials, CHEFKOCH_TIMEOUT_MS);
            chefkochAuth = credentials;
            chefkochFoundRecipes = data.recipes;
            renderChefkochRecipeList();
        });
    });
}

function chefkochRecipeRowHtml(recipe, index) {
    return (
        '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
        '<input type="checkbox" class="form-check-input chefkoch-recipe-checkbox" data-index="' + index + '"' + (recipe.already_imported ? '' : ' checked') + '>' +
        '<div class="flex-grow-1 editable-item-body">' +
        escapeHtml(recipe.name) +
        (recipe.collection ? ' <span class="text-muted small">(' + escapeHtml(recipe.collection) + ')</span>' : '') +
        (recipe.already_imported ? ' <span class="badge text-bg-secondary ms-1">' + escapeHtml(t('admin.chefkoch_import.already_imported')) + '</span>' : '') +
        '</div></div>'
    );
}

/**
 * Replaces the login form with the found-recipes checklist once list()
 * succeeds - chefkochAuth/chefkochFoundRecipes (module state) survive the
 * swap, so "Import selected" below can reuse them without asking again.
 */
function renderChefkochRecipeList() {
    const container = document.getElementById('chefkochImportLoginForm');

    container.innerHTML =
        '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">' +
        '<h2 class="h5 mb-0">' + escapeHtml(t('admin.chefkoch_import.found_heading', { count: chefkochFoundRecipes.length })) + '</h2>' +
        '<div class="d-flex gap-2">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="chefkochSelectNewBtn">' + escapeHtml(t('admin.chefkoch_import.select_new')) + '</button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="chefkochSelectNoneBtn">' + escapeHtml(t('admin.chefkoch_import.select_none')) + '</button>' +
        '</div></div>' +
        '<div id="chefkochRecipeRows">' + chefkochFoundRecipes.map(chefkochRecipeRowHtml).join('') + '</div>' +
        '<button type="button" class="btn btn-primary mt-2" id="chefkochImportBtn"><i class="bi bi-cloud-download"></i> ' + escapeHtml(t('admin.chefkoch_import.import_button')) + '</button>';

    wireChefkochRecipeList();
}

function wireChefkochRecipeList() {
    document.getElementById('chefkochSelectNewBtn').addEventListener('click', () => {
        document.querySelectorAll('.chefkoch-recipe-checkbox').forEach((cb) => {
            cb.checked = !chefkochFoundRecipes[parseInt(cb.dataset.index, 10)].already_imported;
        });
    });
    document.getElementById('chefkochSelectNoneBtn').addEventListener('click', () => {
        document.querySelectorAll('.chefkoch-recipe-checkbox').forEach((cb) => { cb.checked = false; });
    });

    document.getElementById('chefkochImportBtn').addEventListener('click', async (e) => {
        const selected = Array.from(document.querySelectorAll('.chefkoch-recipe-checkbox'))
            .filter((cb) => cb.checked)
            .map((cb) => chefkochFoundRecipes[parseInt(cb.dataset.index, 10)]);

        if (selected.length === 0) {
            showToast(t('admin.chefkoch_import.none_selected'), 'danger');

            return;
        }

        // todo.md "Loading Indicator During Longer Processes" - importing
        // several recipes (each its own fetch + image download) can take a
        // while.
        await withBusyButton(e.currentTarget, async () => {
            const data = await Kochbuch.post('/admin/chefkoch-import/import', Object.assign({}, chefkochAuth, { recipes: selected }), CHEFKOCH_TIMEOUT_MS);
            renderChefkochImportResults(data.results);
        });
    });
}

/**
 * Per-item outcome list, styled like recipe-import-json.js's own
 * importResultsHtml() - a link to review the new recipe, or the translated
 * error code. Links leave the admin shell entirely (it's a separate page
 * from the main SPA, see admin-shell-header.php's doc-comment), hence
 * siteBaseUrl() rather than a plain "#/recipes/..." hash.
 */
function renderChefkochImportResults(results) {
    const container = document.getElementById('chefkochImportResults');
    container.innerHTML = results.map((r) => {
        if (r.status === 'created') {
            return (
                '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
                '<i class="bi bi-check-circle-fill text-success"></i>' +
                '<div class="flex-grow-1 editable-item-body">' +
                '<a href="' + escapeHtml(siteBaseUrl() + '/#/recipes/' + r.id) + '" target="_blank" rel="noopener">' + escapeHtml(r.name) + '</a>' +
                '</div></div>'
            );
        }

        return (
            '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
            '<i class="bi bi-x-circle-fill text-danger"></i>' +
            '<div class="flex-grow-1 editable-item-body">' +
            '<div class="text-muted small">' + escapeHtml(r.name) + '</div>' +
            '<div>' + escapeHtml(translateApiError({ code: r.error_code, message: r.error_code })) + '</div>' +
            '</div></div>'
        );
    }).join('');

    const createdCount = results.filter((r) => r.status === 'created').length;
    showToast(t('admin.chefkoch_import.import_summary', { created: createdCount, total: results.length }));
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminChefkochImport });
