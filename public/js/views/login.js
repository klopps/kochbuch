/**
 * Login screen (route "/login"). No public self-registration - accounts
 * are created by an admin (see CLAUDE.md's planned user management), so
 * this is login-only.
 */
async function renderLogin() {
    const app = document.getElementById('app');

    if (Kochbuch.isLoggedIn()) {
        Router.navigate('/recipes');

        return;
    }

    app.innerHTML =
        '<div class="mx-auto" style="max-width:24rem">' +
        '<h1 class="h3 mb-4 text-center">' + escapeHtml(t('nav.login')) + '</h1>' +
        '<form id="loginForm">' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.username')) + '</label>' +
        '<input type="text" class="form-control" id="loginUsername" autocomplete="username" required></div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.password')) + '</label>' +
        '<input type="password" class="form-control" id="loginPassword" autocomplete="current-password" required></div>' +
        '<div id="loginError" class="alert alert-danger d-none"></div>' +
        '<button type="submit" class="btn btn-primary w-100">' + escapeHtml(t('nav.login')) + '</button>' +
        '</form></div>';

    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorBox = document.getElementById('loginError');
        errorBox.classList.add('d-none');

        try {
            const result = await Kochbuch.post('/auth/login', {
                username: document.getElementById('loginUsername').value,
                password: document.getElementById('loginPassword').value,
            });
            Kochbuch.setToken(result.token);
            await loadCurrentUser();
            renderNav();
            Router.navigate('/recipes');
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        }
    });
}
