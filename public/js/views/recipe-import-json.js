/**
 * "Import from schema.org JSON" (todo.md "Importing schema.org Recipe
 * JSON-LD") - bulk-imports one recipe per uploaded JSON file directly
 * (POST /recipes/import-json, see RecipeController::importJson()/
 * SchemaOrgRecipeParser). Unlike the photo-OCR import, there's no draft
 * review step here: JSON-LD is structured, machine-generated data, not a
 * handwriting-recognition guess, so each valid file becomes a real recipe
 * right away - the results list below is the only feedback, with a link to
 * each created recipe to review/edit afterward if needed.
 */
function renderRecipeImportJson() {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML = recipeImportJsonHtml();
    wireRecipeImportJson();
}

function recipeImportJsonHtml() {
    return (
        '<h1 class="h3 mb-2">' + escapeHtml(t('recipe.import_json_title')) + '</h1>' +
        '<p class="text-muted mb-4">' + escapeHtml(t('recipe.import_json_intro')) + '</p>' +

        '<div id="jsonFileList" class="mb-3"></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-4">' +
        '<label class="btn btn-outline-secondary" style="cursor:pointer">' +
        '<i class="bi bi-file-earmark-plus"></i> ' + escapeHtml(t('recipe.import_json_add_files')) +
        '<input type="file" id="jsonFileInput" accept="application/json,.json" multiple class="d-none">' +
        '</label>' +
        '<button type="button" class="btn btn-primary" id="jsonImportBtn">' +
        '<i class="bi bi-upload"></i> ' + escapeHtml(t('recipe.import_json_run')) +
        '</button>' +
        '</div>' +

        '<div id="jsonError" class="alert alert-danger d-none"></div>' +
        '<div id="jsonProgress" class="d-none text-center text-muted py-3">' +
        '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
        '<span>' + escapeHtml(t('recipe.import_json_importing')) + '</span>' +
        '</div>' +

        '<div id="jsonResults"></div>' +

        '<a href="#/recipes" class="btn btn-outline-secondary">' + escapeHtml(t('recipe.back_to_list')) + '</a>'
    );
}

function wireRecipeImportJson() {
    let selectedFiles = [];

    const list = document.getElementById('jsonFileList');
    const input = document.getElementById('jsonFileInput');
    const errorBox = document.getElementById('jsonError');
    const progress = document.getElementById('jsonProgress');
    const results = document.getElementById('jsonResults');

    function renderFileList() {
        list.innerHTML = selectedFiles.map((file, index) => (
            '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
            '<i class="bi bi-filetype-json"></i>' +
            '<div class="flex-grow-1 editable-item-body">' + escapeHtml(file.name) + '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-index="' + index + '" aria-label="' + escapeHtml(t('recipe.import_photo_remove')) + '"><i class="bi bi-x"></i></button>' +
            '</div>'
        )).join('');

        list.querySelectorAll('button[data-index]').forEach((btn) => {
            btn.addEventListener('click', () => {
                selectedFiles.splice(parseInt(btn.dataset.index, 10), 1);
                renderFileList();
            });
        });
    }

    input.addEventListener('change', () => {
        selectedFiles = selectedFiles.concat(Array.from(input.files));
        input.value = '';
        renderFileList();
    });

    document.getElementById('jsonImportBtn').addEventListener('click', async () => {
        errorBox.classList.add('d-none');
        results.innerHTML = '';

        if (selectedFiles.length === 0) {
            errorBox.textContent = t('recipe.import_json_no_files');
            errorBox.classList.remove('d-none');

            return;
        }

        const formData = new FormData();
        selectedFiles.forEach((file) => formData.append('files[]', file));

        progress.classList.remove('d-none');
        try {
            const data = await Kochbuch.upload('/recipes/import-json', formData);
            results.innerHTML = importResultsHtml(data.results);
            const createdCount = data.results.filter((r) => r.status === 'created').length;
            showToast(t('recipe.import_json_summary', { created: createdCount, total: data.results.length }));
            selectedFiles = [];
            renderFileList();
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        } finally {
            progress.classList.add('d-none');
        }
    });
}

function importResultsHtml(results) {
    return '<div class="mb-4">' + results.map((r) => {
        if (r.status === 'created') {
            return (
                '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
                '<i class="bi bi-check-circle-fill text-success"></i>' +
                '<div class="flex-grow-1 editable-item-body">' +
                '<div class="text-muted small">' + escapeHtml(r.filename) + '</div>' +
                '<a href="#/recipes/' + r.id + '">' + escapeHtml(r.name) + '</a>' +
                '</div></div>'
            );
        }

        return (
            '<div class="d-flex align-items-center gap-2 editable-item mb-2">' +
            '<i class="bi bi-x-circle-fill text-danger"></i>' +
            '<div class="flex-grow-1 editable-item-body">' +
            '<div class="text-muted small">' + escapeHtml(r.filename) + '</div>' +
            '<div>' + escapeHtml(t('recipe.import_json_error_' + r.error_code)) + '</div>' +
            '</div></div>'
        );
    }).join('') + '</div>';
}
