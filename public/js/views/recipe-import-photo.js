/**
 * "Import recipe from photo" (todo.md "Importing Photos of Handwritten
 * Recipes") - the user photographs/uploads one or more pages of a
 * handwritten recipe, they get OCR'd + heuristically parsed server-side
 * (POST /recipes/ocr, see RecipeController::ocr()/RecipeOcrParser), and the
 * resulting best-effort draft is handed off into the existing recipe-form.js
 * create form for full review before anything is ever saved - this view
 * never calls POST /recipes itself.
 */
function renderRecipeImportPhoto(params, query) {
    const app = document.getElementById('app');

    // '1' = PWA share target (sw.js stash), 'native' = Android app
    // (NativeShare / ShareReceiverPlugin).
    const sharedSource = (query && (query.shared === '1' || query.shared === 'native')) ? query.shared : null;

    if (!Kochbuch.isLoggedIn()) {
        if (sharedSource) {
            // Shared into the app while signed out - come back after login
            // (both sources keep the share until it is taken).
            try {
                sessionStorage.setItem('kochbuch_after_login', '/recipes/import-photo?shared=' + sharedSource);
            } catch (e) {
                // Without sessionStorage the share is simply not resumed.
            }
        }
        Router.navigate('/login');

        return;
    }

    app.innerHTML = recipeImportPhotoHtml();
    wireRecipeImportPhoto(sharedSource, (query && query.sharedText) || '');
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
        // Images from Chrome: Chrome refuses to share its own image files
        // with an installed web app (logcat: "Invalid launch URI:
        // content://com.android.chrome.FileProvider/..."), but "Bild
        // kopieren" + this button works - the clipboard never leaves Chrome.
        (clipboardReadSupported()
            ? '<button type="button" class="btn btn-outline-secondary" id="ocrPasteBtn">' +
              '<i class="bi bi-clipboard-plus"></i> ' + escapeHtml(t('recipe.import_photo_paste')) +
              '</button>'
            : '') +
        '<button type="button" class="btn btn-primary" id="ocrRunBtn">' +
        '<i class="bi bi-text-paragraph"></i> ' + escapeHtml(t('recipe.import_photo_run_ocr')) +
        '</button>' +
        '</div>' +

        '<div class="mb-3">' +
        '<label class="form-label" for="ocrUrlInput">' + escapeHtml(t('recipe.import_photo_url_label')) + '</label>' +
        '<input type="url" id="ocrUrlInput" class="form-control" inputmode="url" placeholder="https://…">' +
        '<div class="form-text">' + escapeHtml(t('recipe.import_photo_url_help')) + '</div>' +
        '</div>' +

        '<div class="mb-4">' +
        '<label class="form-label" for="ocrTextInput">' + escapeHtml(t('recipe.import_photo_text_label')) + '</label>' +
        '<textarea id="ocrTextInput" class="form-control" rows="4" placeholder="' + escapeHtml(t('recipe.import_photo_text_placeholder')) + '"></textarea>' +
        '</div>' +

        '<div id="ocrInfo" class="alert alert-info d-none"></div>' +
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

/**
 * Picks up what the service worker stashed when something was shared into
 * the app (sw.js, handleShareTarget(); PWA share target): images become
 * photo pages, shared text fills the recipe-text field, and a shared link
 * (essentially just a URL, maybe with the page title Chrome adds) goes into
 * the link field - the server then reads the recipe behind it
 * (LinkRecipeReader: structured data, page text, or the picture). That is
 * how recipes get here from Chrome, which refuses to hand its own "share
 * image" files to an installed web app.
 * Always clears the stash so a page reload doesn't re-import it.
 *
 * @returns {Promise<{files: File[], text: string, link: string, received: string[]}>}
 */
async function consumeSharedContent(source) {
    const result = { files: [], text: '', link: '', received: [] };
    if (source === 'native') {
        try {
            const share = await NativeShare.take();
            if (share) {
                result.files = share.files;
                const split = splitSharedLink([share.title, share.text].filter(Boolean).join('\n'));
                result.text = split.text;
                result.link = split.link;
                if (share.skipped > 0) {
                    result.received.push(share.skipped + ' Datei(en) nicht lesbar');
                }
            }
        } catch (e) {
            result.received.push(String(e && e.message || e));
        }

        return result;
    }
    if (!('caches' in window)) {
        return result;
    }
    try {
        const cache = await caches.open('kochbuch-share-v1');
        const metaResponse = await cache.match('shared/meta');
        const meta = metaResponse ? await metaResponse.json() : {};
        result.received = Array.isArray(meta.received) ? meta.received : [];
        if (typeof meta.diagnostic === 'string') {
            result.received.push(meta.diagnostic);
        }

        for (const request of await cache.keys()) {
            if (!request.url.includes('/shared/image-')) {
                continue;
            }
            const response = await cache.match(request);
            const blob = await response.blob();
            const name = decodeURIComponent(response.headers.get('X-Filename') || 'shared.jpg');
            result.files.push(new File([blob], name, { type: blob.type }));
        }

        const shared = splitSharedLink([meta.title, meta.text, meta.url].filter(Boolean).join('\n'));
        result.text = shared.text;
        result.link = shared.link;

        for (const request of await cache.keys()) {
            await cache.delete(request);
        }
    } catch (e) {
        // Cache Storage unavailable - behave as if nothing was shared.
    }

    return result;
}

/**
 * Separates a shared link from shared recipe text. "Share page" in Chrome
 * sends the page title plus its URL - that is a link share, not a recipe.
 * As soon as more than one line of text remains besides the URL (e.g. a
 * copied recipe or caption that happens to contain a link) it is text.
 *
 * @returns {{text: string, link: string}}
 */
function splitSharedLink(value) {
    const all = String(value || '').trim();
    const urls = all.match(/https?:\/\/\S+/gi) || [];
    const rest = urls.reduce((acc, url) => acc.replace(url, ''), all).trim();
    const restLines = rest.split(/\r?\n/).filter((line) => line.trim() !== '');
    if (urls.length > 0 && restLines.length <= 1 && rest.length < 200) {
        return { text: '', link: urls[0] };
    }

    return { text: all, link: '' };
}

function wireRecipeImportPhoto(shared, sharedText) {
    let selectedFiles = [];
    let draft = null;

    const grid = document.getElementById('ocrPhotoGrid');
    const input = document.getElementById('ocrPhotoInput');
    const errorBox = document.getElementById('ocrError');
    const progress = document.getElementById('ocrProgress');
    const result = document.getElementById('ocrResult');
    const textInput = document.getElementById('ocrTextInput');
    const urlInput = document.getElementById('ocrUrlInput');
    const infoBox = document.getElementById('ocrInfo');

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

    const pasteBtn = document.getElementById('ocrPasteBtn');
    if (pasteBtn) {
        pasteBtn.addEventListener('click', async () => {
            errorBox.classList.add('d-none');
            infoBox.classList.add('d-none');
            let images;
            let text;
            try {
                ({ images, text } = await readClipboardContent());
            } catch (err) {
                errorBox.textContent = clipboardErrorMessage(err);
                errorBox.classList.remove('d-none');

                return;
            }

            if (images.length > 0) {
                selectedFiles = selectedFiles.concat(images);
                renderThumbs();
            } else if (text !== '') {
                // A copied link or recipe text works too.
                const split = splitSharedLink(text);
                if (split.link !== '') {
                    urlInput.value = split.link;
                } else {
                    textInput.value = split.text;
                }
            } else {
                errorBox.textContent = t('recipe.import_photo_paste_empty');
                errorBox.classList.remove('d-none');
            }
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

        let recipeText = textInput.value.trim();
        // A link pasted into the text field (e.g. a copied image address)
        // belongs in the link field.
        if (urlInput.value.trim() === '' && recipeText !== '') {
            const split = splitSharedLink(recipeText);
            if (split.link !== '') {
                urlInput.value = split.link;
                textInput.value = '';
                recipeText = '';
            }
        }
        const imageUrl = urlInput.value.trim();
        if (selectedFiles.length === 0 && recipeText === '' && imageUrl === '') {
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
            if (recipeText !== '') {
                formData.append('text', recipeText);
            }
            if (imageUrl !== '') {
                formData.append('image_url', imageUrl);
            }
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

    if (sharedText) {
        // Server fallback (no service worker active): text arrives in the URL.
        const split = splitSharedLink(sharedText);
        textInput.value = split.text;
        urlInput.value = split.link;
        if (split.text !== '' || split.link !== '') {
            ocrRunBtn.click();
        }
    }

    if (shared) {
        consumeSharedContent(shared).then((content) => {
            if (content.files.length > 0) {
                selectedFiles = selectedFiles.concat(content.files);
                renderThumbs();
            }
            if (content.text !== '') {
                textInput.value = content.text;
            }
            if (content.link !== '') {
                urlInput.value = content.link;
            }
            if (content.files.length > 0 || content.text !== '' || content.link !== '') {
                ocrRunBtn.click();
            } else if (shared === 'native' && content.received.length === 0) {
                // Native app: the share was already taken (e.g. this view was
                // re-rendered) - nothing to report, just the normal empty form.
            } else {
                // Opened via "share" but nothing usable arrived - say so (and
                // what the app did receive) instead of an unexplained empty form.
                infoBox.textContent = t('recipe.import_photo_shared_empty') +
                    (content.received.length > 0 ? ' (' + content.received.join('; ') + ')' : '');
                infoBox.classList.remove('d-none');
            }
        });
    }

    document.getElementById('ocrUseDraftBtn').addEventListener('click', () => {
        if (!draft) {
            return;
        }

        const notes = draft.notes
            ? t('recipe.import_photo_notes_prefix') + '\n' + draft.notes
            : '';

        // `extra`: further fields a recipe page's structured data provides
        // (servings, times, source, tags, ...) - same names as the form's.
        sessionStorage.setItem('kochbuch_ocr_draft', JSON.stringify({
            ...(draft.extra || {}),
            name: draft.name || '',
            ingredients: draft.ingredients || [],
            steps: draft.steps || [],
            notes,
        }));
        OcrDraftStore.setFiles(selectedFiles);
        Router.navigate('/recipes/new?ocrDraft=1');
    });
}
