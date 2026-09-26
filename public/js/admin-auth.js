/**
 * Client-side gate for every /admin/* page (mirrors YTAN's admin-auth.js -
 * see CLAUDE.md's planned admin area section). There is no server-side
 * check before the HTML renders: every /admin/* route in App.php is a
 * plain unauthenticated route, same as the main SPA shell. The real gate
 * is server-side on the API via BaseController::requireAdmin() - this only
 * toggles which of #loginBox / #adminAppWrapper is visible (both are
 * always rendered) and wires the login form.
 */
function initAdminAuth(options) {
    const content = document.getElementById(options.contentId);
    const loginBox = document.getElementById('loginBox');
    const errorBox = document.getElementById('loginError');

    function showLogin(message) {
        loginBox.classList.remove('d-none');
        content.classList.add('d-none');
        if (message) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        } else {
            errorBox.classList.add('d-none');
        }
    }

    async function checkAuth() {
        if (!Kochbuch.isLoggedIn()) {
            showLogin();

            return;
        }
        try {
            const user = await Kochbuch.get('/auth/me');
            if (!user.is_admin) {
                Kochbuch.setToken(null);
                showLogin(t('admin.login.not_admin'));

                return;
            }
            loginBox.classList.add('d-none');
            content.classList.remove('d-none');
            adminOnAuthenticated(user);
            if (options.onReady) {
                options.onReady(user);
            }
        } catch (e) {
            Kochbuch.setToken(null);
            showLogin();
        }
    }

    document.getElementById('adminLoginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const result = await Kochbuch.post('/auth/login', {
                username: document.getElementById('loginUsername').value,
                password: document.getElementById('loginPassword').value,
            });
            Kochbuch.setToken(result.token);
            await checkAuth();
        } catch (err) {
            showLogin(translateApiError(err.data) || err.message);
        }
    });

    checkAuth();
}
