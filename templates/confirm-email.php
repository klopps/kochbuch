<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= htmlspecialchars($translator->t('auth.confirm_email_title')) ?></title>
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
        <h1 class="h3 mb-4 text-center" data-i18n="auth.confirm_email_title"></h1>
        <p class="text-center" data-i18n="auth.confirm_email_subtitle"></p>
        <div id="confirmEmailError" class="alert alert-danger d-none"></div>
        <button type="button" id="confirmEmailBtn" class="btn btn-primary w-100" data-i18n="auth.confirm_email_submit"></button>
    </div>

    <script src="<?= $baseUrl ?>/js/i18n.js"></script>
    <script src="<?= $baseUrl ?>/js/helper.js"></script>
    <script src="<?= $baseUrl ?>/js/api-client.js"></script>
    <script>
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });

        var token = <?= json_encode($_GET['token'] ?? '') ?>;

        document.getElementById('confirmEmailBtn').addEventListener('click', async function () {
            var errorBox = document.getElementById('confirmEmailError');
            errorBox.classList.add('d-none');

            if (!token) {
                errorBox.textContent = t('auth.missing_token');
                errorBox.classList.remove('d-none');
                return;
            }

            document.getElementById('confirmEmailBtn').disabled = true;

            try {
                var result = await Kochbuch.post('/auth/confirm-email-change', { token: token });
                Kochbuch.setToken(result.token);
                window.location.href = '<?= $baseUrl ?>/';
            } catch (err) {
                document.getElementById('confirmEmailBtn').disabled = false;
                errorBox.textContent = translateApiError(err.data) || err.message;
                errorBox.classList.remove('d-none');
            }
        });
    </script>
</body>
</html>
