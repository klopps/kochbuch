/**
 * Admin bulk photo import (todo.md "Bulk Photo Import", /admin/photo-import):
 * many recipe photos are recognized and saved as recipes one after another,
 * one photo = one recipe, for the chosen owner and visibility, each tagged
 * "foto-import" (PhotoImportController). The photos are sent one at a time
 * so no single request can run into the server's time limit; a Gemini
 * per-minute limit (HTTP 429 with retry_at) is waited out automatically and
 * the same photo retried, a daily limit stops the run (the rest stays
 * queued for a later start). "Stopp" finishes the current photo and halts.
 */
const PHOTO_IMPORT_MAX_RETRIES = 3;
let photoImportItems = [];  // {file, status: 'queued'|'running'|'waiting'|'done'|'error'|'stopped', detail, recipe}
let photoImportStopRequested = false;
let photoImportRunning = false;

async function initAdminPhotoImport(currentUser) {
    const ownerSelect = document.getElementById('photoImportOwner');
    try {
        const users = await Kochbuch.get('/users');
        ownerSelect.innerHTML = users
            .filter((u) => u.is_active === undefined || Number(u.is_active) === 1)
            .map((u) => '<option value="' + u.id + '"' + (currentUser && u.id === currentUser.id ? ' selected' : '') + '>' +
                escapeHtml(u.username + (u.firstname || u.lastname ? ' (' + [u.firstname, u.lastname].filter(Boolean).join(' ') + ')' : '')) + '</option>')
            .join('');
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }

    document.getElementById('photoImportFiles').addEventListener('change', (e) => {
        if (photoImportRunning) {
            return;
        }
        photoImportItems = Array.from(e.target.files).map((file) => ({ file, status: 'queued', detail: '', recipe: null }));
        renderPhotoImportList();
    });
    document.getElementById('photoImportStartBtn').addEventListener('click', runPhotoImport);
    document.getElementById('photoImportStopBtn').addEventListener('click', () => {
        photoImportStopRequested = true;
        document.getElementById('photoImportStopBtn').disabled = true;
    });
}

function photoImportStatusHtml(item) {
    const label = t('admin.photo_import.status_' + item.status);
    const badge = {
        queued: 'text-bg-secondary', running: 'text-bg-primary', waiting: 'text-bg-warning',
        done: 'text-bg-success', error: 'text-bg-danger', stopped: 'text-bg-secondary',
    }[item.status];

    return '<span class="badge ' + badge + '">' + escapeHtml(label) + '</span>' +
        (item.detail ? '<div class="small text-muted">' + escapeHtml(item.detail) + '</div>' : '');
}

function renderPhotoImportList() {
    const list = document.getElementById('photoImportList');
    if (photoImportItems.length === 0) {
        list.innerHTML = '';
        document.getElementById('photoImportSummary').innerHTML = '';

        return;
    }
    const appBase = window.KOCHBUCH_API_BASE.replace(/\/api\/v1$/, '');
    list.innerHTML =
        '<div class="table-responsive"><table class="table align-middle">' +
        '<thead><tr><th>' + escapeHtml(t('admin.photo_import.col_file')) + '</th><th>' + escapeHtml(t('admin.photo_import.col_status')) + '</th><th>' + escapeHtml(t('admin.photo_import.col_recipe')) + '</th></tr></thead><tbody>' +
        photoImportItems.map((item) => (
            '<tr>' +
            '<td class="text-break">' + escapeHtml(item.file.name) + '</td>' +
            '<td>' + photoImportStatusHtml(item) + '</td>' +
            '<td>' + (item.recipe
                ? '<a href="' + appBase + '/#/recipes/' + item.recipe.id + '" target="_blank" rel="noopener">' + escapeHtml(item.recipe.name) + '</a>' +
                  '<div class="small text-muted">' + escapeHtml(t('admin.photo_import.counts', { ingredients: item.recipe.ingredient_count, steps: item.recipe.step_count })) + '</div>'
                : '') + '</td>' +
            '</tr>'
        )).join('') +
        '</tbody></table></div>';

    const count = (status) => photoImportItems.filter((i) => i.status === status).length;
    document.getElementById('photoImportSummary').innerHTML =
        '<span class="small">' + escapeHtml(t('admin.photo_import.summary', {
            total: photoImportItems.length, done: count('done'), errors: count('error'), open: count('queued') + count('stopped'),
        })) + '</span>';
}

/**
 * Same idea as recipe-import-photo.js's prepareImageForOcr(): phone photos
 * are often over the server's 5 MB limit and much sharper than recognition
 * needs - long edge to 2000 px, JPEG, EXIF rotation applied. Falls back to
 * the original on any failure.
 */
async function photoImportDownscale(file) {
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

        return blob ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) {
        return file;
    }
}

function photoImportSleep(seconds, item) {
    return new Promise((resolve) => {
        let left = seconds;
        const tick = () => {
            if (photoImportStopRequested || left <= 0) {
                resolve();

                return;
            }
            item.detail = t('admin.photo_import.waiting', { seconds: left });
            renderPhotoImportList();
            left--;
            setTimeout(tick, 1000);
        };
        tick();
    });
}

async function runPhotoImport() {
    if (photoImportRunning) {
        return;
    }
    const pending = photoImportItems.filter((i) => i.status === 'queued' || i.status === 'stopped' || i.status === 'error');
    if (pending.length === 0) {
        showToast(t('admin.photo_import.no_files'), 'danger');

        return;
    }
    const ownerId = document.getElementById('photoImportOwner').value;
    const visibility = document.getElementById('photoImportVisibility').value;
    if (!ownerId) {
        showToast(t('admin.photo_import.owner_missing'), 'danger');

        return;
    }

    photoImportRunning = true;
    photoImportStopRequested = false;
    const startBtn = document.getElementById('photoImportStartBtn');
    const stopBtn = document.getElementById('photoImportStopBtn');
    startBtn.disabled = true;
    stopBtn.disabled = false;
    stopBtn.classList.remove('d-none');
    document.getElementById('photoImportFiles').disabled = true;

    let dailyLimitHit = false;
    for (const item of pending) {
        if (photoImportStopRequested || dailyLimitHit) {
            item.status = 'stopped';
            item.detail = dailyLimitHit ? t('admin.photo_import.stopped_daily') : '';
            continue;
        }
        item.status = 'running';
        item.detail = '';
        renderPhotoImportList();

        for (let attempt = 0; ; attempt++) {
            try {
                const formData = new FormData();
                formData.append('image', await photoImportDownscale(item.file));
                formData.append('owner_id', ownerId);
                formData.append('visibility', visibility);
                item.recipe = await Kochbuch.upload('/admin/photo-import', formData, 120000);
                item.status = 'done';
                item.detail = '';
                break;
            } catch (err) {
                const code = err.data && err.data.code;
                if (code === 'recipe.ocr_rate_limited_minute' && attempt < PHOTO_IMPORT_MAX_RETRIES && !photoImportStopRequested) {
                    item.status = 'waiting';
                    await photoImportSleep(Math.min(120, Math.max(5, Number(err.data.retry_in_seconds) || 60)) + 2, item);
                    item.status = 'running';
                    item.detail = '';
                    renderPhotoImportList();
                    continue;
                }
                item.status = 'error';
                item.detail = translateApiError(err.data) || err.message;
                if (code === 'recipe.ocr_rate_limited_day') {
                    dailyLimitHit = true;
                }
                break;
            }
        }
        renderPhotoImportList();
    }

    renderPhotoImportList();
    photoImportRunning = false;
    startBtn.disabled = false;
    stopBtn.classList.add('d-none');
    document.getElementById('photoImportFiles').disabled = false;
    showToast(t('admin.photo_import.finished'));
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminPhotoImport });
