<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= htmlspecialchars($translator->t('auth.forgot_password_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />

    <script>window.KOCHBUCH_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.KOCHBUCH_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

    <script src="<?= $baseUrl ?>/js/settings.js"></script>
    <script>applyStoredTheme();</script>
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Kochbuch\App::assetVersion($rootDir, '/css/style.css') ?>" />
</head>
<body>
    <div class="container py-4" style="max-width:26rem">
        <h1 class="h3 mb-2 text-center" data-i18n="auth.forgot_password_title"></h1>
        <p class="text-muted text-center" data-i18n="auth.forgot_password_subtitle"></p>
        <form id="forgotPasswordForm">
            <div class="mb-3">
                <label class="form-label" data-i18n="auth.username"></label>
                <input id="fpIdentifier" type="text" class="form-control" autocomplete="username" required>
            </div>
            <div id="fpMessage" class="alert alert-info d-none"></div>
            <button type="submit" class="btn btn-primary w-100" id="fpSubmitBtn" data-i18n="auth.forgot_password_submit"></button>
        </form>
        <p class="text-center mt-3">
            <a href="<?= $baseUrl ?>/#/login"><i class="bi bi-arrow-left"></i> <span data-i18n="auth.back_to_login"></span></a>
        </p>
    </div>

    <script src="<?= $baseUrl ?>/js/i18n.js"></script>
    <script src="<?= $baseUrl ?>/js/helper.js"></script>
    <script src="<?= $baseUrl ?>/js/api-client.js"></script>
    <script>
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });

        document.getElementById('forgotPasswordForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            var messageBox = document.getElementById('fpMessage');
            var btn = document.getElementById('fpSubmitBtn');
            btn.disabled = true;

            try {
                await Kochbuch.post('/auth/forgot-password', { username: document.getElementById('fpIdentifier').value });
            } catch (err) {
                // Deliberately ignored - the backend already returns the same
                // generic response whether or not the account exists
                // (anti-enumeration); a network/server error gets the same
                // message here too, rather than leaking which case occurred.
            }
            messageBox.textContent = t('auth.forgot_password_success');
            messageBox.classList.remove('d-none');
        });
    </script>
</body>
</html>
