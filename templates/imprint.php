<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | Impressum</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <script src="<?= $baseUrl ?>/js/settings.js"></script>
    <script>applyStoredTheme();</script>
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Kochbuch\App::assetVersion($rootDir, '/css/style.css') ?>" />
</head>
<body>
    <div class="container py-4" style="max-width:40rem">
        <p class="mb-4"><a href="<?= $baseUrl ?>/" class="link-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($appName) ?></a></p>
        <h1>Impressum</h1>
        <h2 class="h5">Angaben gemäß § 5 TMG:</h2>
        Christoph Steindorff
        <h3>Postanschrift</h3>
        Ohnhorster Weg 14<br>38527 Meine
        <h3>Kontakt</h3>
        Telefon: +49 5304 501120<br>
        <!-- TODO: replace with a YTAN-specific contact address if different from PESR's -->
        E-Mail: info@pesr.org
    </div>
</body>
</html>