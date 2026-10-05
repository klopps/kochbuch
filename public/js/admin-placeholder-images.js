/**
 * Admin CRUD for keyword->image mappings (/admin/placeholder-images,
 * todo.md "Placeholders for Missing Images"). A small, hand-managed list
 * (no pagination/filtering, unlike admin-quickeditor.js/admin-user.js) - one
 * inline form (always the same DOM, toggled open/closed and re-purposed for
 * create vs edit via a hidden id field) above the table, same "inline
 * create form above the list" shape as public/js/views/categories.js.
 */
let adminPlaceholderImagesLastList = [];

async function initAdminPlaceholderImages() {
    document.getElementById('placeholderImageMenuAction').innerHTML =
        '<button type="button" class="btn btn-primary btn-sm" id="placeholderImageCreateBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('admin.placeholder_images.create')) + '</button>';
    document.getElementById('placeholderImageCreateBtn').addEventListener('click', () => showPlaceholderImageForm(null));

    loadPlaceholderImageList();
}

async function loadPlaceholderImageList() {
    const list = document.getElementById('placeholderImageList');
    list.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        adminPlaceholderImagesLastList = await Kochbuch.get('/admin/placeholder-images');
        renderPlaceholderImageList();
    } catch (err) {
        list.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function keywordsForLocale(row, locale) {
    return (row.keywords || []).filter((k) => k.locale === locale).map((k) => k.keyword).join(', ');
}

function renderPlaceholderImageList() {
    const list = document.getElementById('placeholderImageList');

    if (adminPlaceholderImagesLastList.length === 0) {
        list.innerHTML = '<div class="empty-state"><i class="bi bi-images"></i><p class="mb-0">' + escapeHtml(t('admin.placeholder_images.empty')) + '</p></div>';

        return;
    }

    list.innerHTML =
        '<div class="table-responsive"><table class="table align-middle">' +
        '<tbody>' + adminPlaceholderImagesLastList.map(placeholderImageRowHtml).join('') + '</tbody>' +
        '</table></div>';

    list.querySelectorAll('.placeholder-image-edit-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = adminPlaceholderImagesLastList.find((r) => r.id === parseInt(btn.dataset.id, 10));
            showPlaceholderImageForm(row);
        });
    });
    list.querySelectorAll('.placeholder-image-delete-btn').forEach((btn) => {
        btn.addEventListener('click', () => deletePlaceholderImage(parseInt(btn.dataset.id, 10)));
    });
}

function placeholderImageRowHtml(row) {
    const keywordsDe = keywordsForLocale(row, 'de');
    const keywordsEn = keywordsForLocale(row, 'en');

    return (
        '<tr>' +
        '<td><img src="' + escapeHtml(placeholderImageUrl(row.filename)) + '" alt="" style="width:3rem;height:3rem;object-fit:cover;border-radius:.375rem"></td>' +
        '<td>' +
        (row.is_default ? '<span class="badge text-bg-primary mb-1">' + escapeHtml(t('admin.placeholder_images.default_badge')) + '</span><br>' : '') +
        '<span class="small text-muted">DE:</span> ' + escapeHtml(keywordsDe || '–') + '<br>' +
        '<span class="small text-muted">EN:</span> ' + escapeHtml(keywordsEn || '–') +
        '</td>' +
        '<td class="text-end">' +
        '<div class="dropdown">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        '<li><button type="button" class="dropdown-item placeholder-image-edit-btn" data-id="' + row.id + '"><i class="bi bi-pencil"></i> ' + escapeHtml(t('admin.placeholder_images.edit')) + '</button></li>' +
        '<li><button type="button" class="dropdown-item text-danger placeholder-image-delete-btn" data-id="' + row.id + '"><i class="bi bi-trash"></i> ' + escapeHtml(t('admin.placeholder_images.delete')) + '</button></li>' +
        '</ul>' +
        '</div>' +
        '</td></tr>'
    );
}

function showPlaceholderImageForm(row) {
    const container = document.getElementById('placeholderImageForm');
    const isEdit = row !== null;

    container.innerHTML =
        '<form id="placeholderImageEditForm" class="card card-body mb-3">' +
        '<div class="mb-2">' +
        '<label class="form-label small">' + escapeHtml(t('admin.placeholder_images.image')) + (isEdit ? '' : ' *') + '</label>' +
        (isEdit ? '<img src="' + escapeHtml(placeholderImageUrl(row.filename)) + '" alt="" class="d-block mb-2" style="width:4rem;height:4rem;object-fit:cover;border-radius:.375rem">' : '') +
        '<input type="file" class="form-control" id="pfImageFile" accept="image/jpeg,image/png,image/webp"' + (isEdit ? '' : ' required') + '>' +
        '</div>' +
        '<div class="mb-2">' +
        '<label class="form-label small">' + escapeHtml(t('admin.placeholder_images.keywords_de')) + '</label>' +
        '<input type="text" class="form-control" id="pfKeywordsDe" value="' + escapeHtml(isEdit ? keywordsForLocale(row, 'de') : '') + '">' +
        '</div>' +
        '<div class="mb-2">' +
        '<label class="form-label small">' + escapeHtml(t('admin.placeholder_images.keywords_en')) + '</label>' +
        '<input type="text" class="form-control" id="pfKeywordsEn" value="' + escapeHtml(isEdit ? keywordsForLocale(row, 'en') : '') + '">' +
        '</div>' +
        '<div class="form-check mb-3">' +
        '<input class="form-check-input" type="checkbox" id="pfIsDefault"' + (isEdit && row.is_default ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="pfIsDefault">' + escapeHtml(t('admin.placeholder_images.is_default')) + '</label>' +
        '</div>' +
        '<div class="d-flex gap-2">' +
        '<button type="submit" class="btn btn-primary btn-sm">' + escapeHtml(t('admin.placeholder_images.save')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="pfCancelBtn">' + escapeHtml(t('admin.placeholder_images.cancel')) + '</button>' +
        '</div>' +
        '</form>';

    document.getElementById('pfCancelBtn').addEventListener('click', () => { container.innerHTML = ''; });
    document.getElementById('placeholderImageEditForm').addEventListener('submit', (e) => {
        e.preventDefault();
        savePlaceholderImage(isEdit ? row.id : null, e.submitter);
    });
}

async function savePlaceholderImage(id, button) {
    const fileInput = document.getElementById('pfImageFile');
    const formData = new FormData();
    if (fileInput.files[0]) {
        formData.append('image', fileInput.files[0]);
    } else if (id === null) {
        showToast(t('admin.placeholder_images.image_required'), 'danger');

        return;
    }
    formData.append('keywords_de', document.getElementById('pfKeywordsDe').value);
    formData.append('keywords_en', document.getElementById('pfKeywordsEn').value);
    formData.append('is_default', document.getElementById('pfIsDefault').checked ? '1' : '');

    // todo.md "Loading Indicator During Longer Processes" - an image upload
    // in particular can take a moment on a slow connection.
    await withBusyButton(button, async () => {
        if (id === null) {
            await Kochbuch.upload('/admin/placeholder-images', formData);
            showToast(t('admin.placeholder_images.created'));
        } else {
            await Kochbuch.upload('/admin/placeholder-images/' + id, formData);
            showToast(t('admin.placeholder_images.updated'));
        }
        document.getElementById('placeholderImageForm').innerHTML = '';
        loadPlaceholderImageList();
    });
}

async function deletePlaceholderImage(id) {
    if (!window.confirm(t('admin.placeholder_images.delete_confirm'))) {
        return;
    }
    try {
        await Kochbuch.del('/admin/placeholder-images/' + id);
        showToast(t('admin.placeholder_images.deleted'));
        loadPlaceholderImageList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminPlaceholderImages });
