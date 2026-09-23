/**
 * Minimal Bootstrap toast helper - #toastHost is a fixed-position container
 * (public/css/style.css) that templates/app.php renders once; every call
 * appends and auto-removes its own toast element.
 */
function showToast(message, variant) {
    variant = variant || 'success';
    const host = document.getElementById('toastHost');
    if (!host) {
        return;
    }

    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + variant + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML =
        '<div class="d-flex">' +
        '<div class="toast-body"></div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
        '</div>';
    el.querySelector('.toast-body').textContent = message;
    host.appendChild(el);

    const toast = new bootstrap.Toast(el, { delay: 4000 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    toast.show();
}
