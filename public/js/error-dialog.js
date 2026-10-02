/**
 * In-UI error modal (todo.md "Loading Indicator During Longer Processes") -
 * used by withBusyButton() (helper.js) when a long-running action fails or
 * times out. A modal rather than this app's usual toast (toast.js) is
 * deliberate here: a save failure the user is actively waiting on shouldn't
 * be missable the way a toast scrolling past could be. Same Bootstrap-modal
 * construction as confirm-dialog.js's showConfirmDialog(), just a single
 * dismiss button instead of confirm/cancel.
 */
function showErrorDialog(message) {
    return new Promise((resolve) => {
        const el = document.createElement('div');
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-body d-flex gap-3 align-items-start">' +
            '<i class="bi bi-x-circle-fill text-danger fs-3"></i>' +
            '<p class="mb-0"></p>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="errorDialogOk"></button>' +
            '</div></div></div>';
        el.querySelector('p').textContent = message;
        el.querySelector('#errorDialogOk').textContent = t('common.close');
        document.body.appendChild(el);

        const modal = new bootstrap.Modal(el);

        el.addEventListener('shown.bs.modal', () => {
            el.querySelector('#errorDialogOk').focus();
        });
        el.addEventListener('hidden.bs.modal', () => {
            el.remove();
            resolve();
        });

        modal.show();
    });
}
