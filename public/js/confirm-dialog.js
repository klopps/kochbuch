/**
 * In-UI confirmation dialog replacing native confirm() for delete/action
 * confirmations (todo.md "Sicherheitsabfrage beim Löschen") - a Bootstrap
 * modal instead of a browser MessageBox, matching the rest of the app's
 * design (mirrors YTAN's public/js/confirm-dialog.js Promise<boolean> shape,
 * but rendered with a Bootstrap Modal since that's this project's actual UI
 * toolkit rather than YTAN's hand-rolled CSS overlay).
 */
function showConfirmDialog(message, options) {
    const opts = options || {};
    const variant = opts.type === 'danger' ? 'danger' : 'primary';
    const confirmLabel = opts.confirmLabel || t(variant === 'danger' ? 'common.delete' : 'common.confirm');
    const cancelLabel = opts.cancelLabel || t('common.cancel');

    return new Promise((resolve) => {
        const el = document.createElement('div');
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-body d-flex gap-3 align-items-start">' +
            '<i class="bi bi-exclamation-triangle-fill text-' + variant + ' fs-3"></i>' +
            '<p class="mb-0"></p>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="confirmDialogCancel"></button>' +
            '<button type="button" class="btn btn-' + variant + '" id="confirmDialogConfirm"></button>' +
            '</div></div></div>';
        el.querySelector('p').textContent = message;
        el.querySelector('#confirmDialogCancel').textContent = cancelLabel;
        el.querySelector('#confirmDialogConfirm').textContent = confirmLabel;
        document.body.appendChild(el);

        const modal = new bootstrap.Modal(el);
        let confirmed = false;

        el.querySelector('#confirmDialogConfirm').addEventListener('click', () => {
            confirmed = true;
            modal.hide();
        });

        // Escape and backdrop-click already close the modal via Bootstrap's
        // own defaults, leaving confirmed=false - the same cancel-by-default
        // behavior as native confirm()/YTAN's dialog.
        el.addEventListener('shown.bs.modal', () => {
            el.querySelector('#confirmDialogCancel').focus();
        });
        el.addEventListener('hidden.bs.modal', () => {
            el.remove();
            resolve(confirmed);
        });

        modal.show();
    });
}
