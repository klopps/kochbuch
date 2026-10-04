/**
 * "Import recipe from photo" (todo.md "Importing Photos of Handwritten
 * Recipes") - the user photographs/uploads one or more pages of a
 * handwritten recipe, they get OCR'd + heuristically parsed server-side
 * (POST /recipes/ocr, see RecipeController::ocr()/RecipeOcrParser), and the
 * resulting best-effort draft is handed off into the existing recipe-form.js
 * create form for full review before anything is ever saved - this view
 * never calls POST /recipes itself.
 */
function renderRecipeImportPhoto() {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML = recipeImportPhotoHtml();
    wireRecipeImportPhoto();
}

function recipeImportPhotoHtml() {
    return (
        '<h1 class="h3 mb-2">' + escapeHtml(t('recipe.import_photo_title')) + '</h1>' +
        '<p class="text-muted mb-2">' + escapeHtml(t('recipe.import_photo_intro')) + '</p>' +
        '<p class="text-muted small mb-4"><i class="bi bi-shield-lock"></i> ' + escapeHtml(t('recipe.import_photo_privacy')) + '</p>' +

        '<div class="image-thumb-grid mb-3" id="ocrPhotoGrid"></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-4">' +
        '<label class="btn btn-outline-secondary" style="cursor:pointer">' +
        '<i class="bi bi-image"></i> ' + escapeHtml(t('recipe.import_photo_add_photo')) +
        // Two separate inputs on purpose (todo.md "Photo Import" + the
        // earlier "Importing Photos on a Smartphone Without Access to the
        // Gallery"): a single input cannot offer both on Android - with
        // `capture` set the browser jumps straight into the camera and hides
        // the gallery, without it the chooser often lists only the gallery.
        // So: this one (no `capture`) picks existing photos, the next one
        // (`capture`) opens the camera directly.
        '<input type="file" id="ocrPhotoInput" accept="image/jpeg,image/png,image/webp" multiple class="d-none">' +
        '</label>' +
        '<label class="btn btn-outline-secondary" style="cursor:pointer">' +
        '<i class="bi bi-camera"></i> ' + escapeHtml(t('recipe.import_photo_take_photo')) +
        '<input type="file" id="ocrCameraInput" accept="image/*" capture="environment" class="d-none">' +
        '</label>' +
        '<button type="button" class="btn btn-primary" id="ocrRunBtn">' +
        '<i class="bi bi-text-paragraph"></i> ' + escapeHtml(t('recipe.import_photo_run_ocr')) +
        '</button>' +
        '</div>' +

        '<div id="ocrError" class="alert alert-danger d-none"></div>' +
        '<div id="ocrProgress" class="d-none text-center text-muted py-3">' +
        '<div class="spinner-border spinner-border-sm me-2" role="status"></div>' +
        '<span>' + escapeHtml(t('recipe.import_photo_recognizing')) + '</span>' +
        '</div>' +

        '<div id="ocrResult" class="d-none">' +
        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.import_photo_recognized_text')) + '</h2>' +
        '<textarea id="ocrRawText" class="form-control mb-3" rows="6" readonly></textarea>' +
        '<div class="d-flex gap-2 mb-4">' +
        '<button type="button" class="btn btn-primary" id="ocrUseDraftBtn">' +
        '<i class="bi bi-check-lg"></i> ' + escapeHtml(t('recipe.import_photo_use_draft')) +
        '</button>' +
        '</div>' +
        '</div>' +

        '<a href="#/recipes" class="btn btn-outline-secondary">' + escapeHtml(t('recipe.cancel')) + '</a>'
    );
}

/**
 * Phone photos are often 5-12 MB (over the server's 5 MB OCR limit) and far
 * sharper than text recognition needs - shrinks the long edge to 2000 px and
 * re-encodes as JPEG, which also cuts the AI service's per-photo cost.
 * createImageBitmap() applies the EXIF orientation, so rotated phone photos
 * arrive upright. Any failure (unsupported format, no canvas) falls back to
 * the untouched original.
 */
async function prepareImageForOcr(file) {
    const MAX_EDGE = 2000;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size <= 1024 * 1024) {
            bitmap.close();

            return file;
        }
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));

        return blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) {
        return file;
    }
}

function wireRecipeImportPhoto() {
    let selectedFiles = [];
    let draft = null;

    const grid = document.getElementById('ocrPhotoGrid');
    const input = document.getElementById('ocrPhotoInput');
    const errorBox = document.getElementById('ocrError');
    const progress = document.getElementById('ocrProgress');
    const result = document.getElementById('ocrResult');

    function renderThumbs() {
        grid.innerHTML = selectedFiles.map((file, index) => (
            '<div class="image-thumb">' +
            '<img src="' + URL.createObjectURL(file) + '" alt="">' +
            '<button type="button" class="image-thumb-remove" data-index="' + index + '" aria-label="' + escapeHtml(t('recipe.import_photo_remove')) + '">&times;</button>' +
            '</div>'
        )).join('');

        grid.querySelectorAll('.image-thumb-remove').forEach((btn) => {
            btn.addEventListener('click', () => {
                selectedFiles.splice(parseInt(btn.dataset.index, 10), 1);
                renderThumbs();
            });
        });
    }

    [input, document.getElementById('ocrCameraInput')].forEach((el) => {
        el.addEventListener('change', () => {
            selectedFiles = selectedFiles.concat(Array.from(el.files));
            el.value = '';
            renderThumbs();
        });
    });

    const ocrRunBtn = document.getElementById('ocrRunBtn');
    ocrRunBtn.addEventListener('click', async () => {
        errorBox.classList.add('d-none');
        result.classList.add('d-none');

        if (selectedFiles.length === 0) {
            errorBox.textContent = t('recipe.import_photo_no_pages');
            errorBox.classList.remove('d-none');

            return;
        }

        const formData = new FormData();

        // todo.md "Loading Indicator During Longer Processes" - this view
        // already has its own descriptive progress indicator (#ocrProgress)
        // richer than withBusyButton()'s plain spinner, but still needs the
        // button disabled meanwhile so a second click can't start a
        // concurrent OCR run on the same photos.
        ocrRunBtn.disabled = true;
        progress.classList.remove('d-none');
        try {
            // Downscaled copies only for the upload - selectedFiles keeps
            // the originals, which are what gets attached to the recipe.
            const prepared = await Promise.all(selectedFiles.map(prepareImageForOcr));
            prepared.forEach((file) => formData.append('images[]', file));
            draft = await Kochbuch.upload('/recipes/ocr', formData);
            document.getElementById('ocrRawText').value = draft.raw_text || '';
            result.classList.remove('d-none');
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        } finally {
            progress.classList.add('d-none');
            ocrRunBtn.disabled = false;
        }
    });

    document.getElementById('ocrUseDraftBtn').addEventListener('click', () => {
        if (!draft) {
            return;
        }

        const notes = draft.notes
            ? t('recipe.import_photo_notes_prefix') + '\n' + draft.notes
            : '';

        sessionStorage.setItem('kochbuch_ocr_draft', JSON.stringify({
            name: draft.name || '',
            ingredients: draft.ingredients || [],
            steps: draft.steps || [],
            notes,
        }));
        OcrDraftStore.setFiles(selectedFiles);
        Router.navigate('/recipes/new?ocrDraft=1');
    });
}
