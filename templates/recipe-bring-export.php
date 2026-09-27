<?php
/**
 * Minimal, standalone, server-rendered page (not part of the SPA) that
 * Bring!'s server fetches unauthenticated to parse this recipe's
 * ingredients via schema.org Recipe microdata (todo.md "Anbindung der
 * Einkaufs-App Bring!") - see BringService/RecipeController::bringExport().
 * Reached only through a short-lived, unguessable token
 * (RecipeRepository::createBringExportToken()) - the App.php route
 * requiring this file has already resolved that token into $recipe before
 * requiring this file, or returned 404 itself without ever reaching here.
 *
 * Deliberately minimal: only name, servings, and ingredients - no
 * description, images, steps, notes or allergen info, keeping whatever a
 * visitor with the (short-lived, hard-to-guess) link could see as small as
 * possible. Heading rows are skipped - schema.org's recipeIngredient has no
 * concept of a section heading.
 */
$ingredients = array_values(array_filter($recipe['ingredients'], fn (array $i) => empty($i['is_heading'])));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($recipe['name']) ?></title>
</head>
<body>
    <div itemscope itemtype="http://schema.org/Recipe">
        <h1 itemprop="name"><?= htmlspecialchars($recipe['name']) ?></h1>
        <meta itemprop="recipeYield" content="<?= (int) $recipe['servings'] ?>">
        <ul>
            <?php foreach ($ingredients as $ingredient): ?>
            <li itemprop="recipeIngredient"><?= htmlspecialchars(\Kochbuch\Http\Controllers\RecipeController::pdfIngredientLine($ingredient)) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
