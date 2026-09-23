/**
 * Updates the dynamic parts of the navbar (offcanvas auth area, theme
 * toggle label) - the static structure (brand, links, offcanvas shell)
 * lives in templates/app.php itself.
 */
function renderNav() {
    const authArea = document.getElementById('navAuthArea');
    if (!authArea) {
        return;
    }

    if (currentUser) {
        authArea.innerHTML =
            '<div class="small text-muted mb-1"><i class="bi bi-person-circle"></i> ' + escapeHtml(currentUser.username) + '</div>' +
            '<a href="#/recipes?mine=1" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('recipe.my_recipes')) + '</a>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" id="logoutBtn">' + escapeHtml(t('nav.logout')) + '</button>';

        document.getElementById('logoutBtn').addEventListener('click', () => {
            Kochbuch.setToken(null);
            currentUser = null;
            renderNav();
            Router.navigate('/recipes');
        });
    } else {
        authArea.innerHTML =
            '<a href="#/login" class="btn btn-primary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('nav.login')) + '</a>';
    }

    updateThemeToggleLabel();
}

function updateThemeToggleLabel() {
    const label = document.getElementById('themeToggleLabel');
    const icon = document.getElementById('themeToggleIcon');
    if (!label) {
        return;
    }
    const isDark = loadSettings().theme === 'dark';
    label.textContent = isDark ? t('theme.light') : t('theme.dark');
    if (icon) {
        icon.className = 'bi ' + (isDark ? 'bi-sun' : 'bi-moon-stars');
    }
}

function wireThemeToggle() {
    const btn = document.getElementById('themeToggleBtn');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', () => {
        const next = loadSettings().theme === 'dark' ? 'light' : 'dark';
        setTheme(next);
        updateThemeToggleLabel();
    });
}

function wireLanguageSwitcher() {
    const buttons = document.querySelectorAll('.lang-btn');
    if (!buttons.length) {
        return;
    }
    const active = window.KOCHBUCH_LOCALE;
    buttons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === active);
        btn.addEventListener('click', () => setLanguage(btn.dataset.lang));
    });
}
