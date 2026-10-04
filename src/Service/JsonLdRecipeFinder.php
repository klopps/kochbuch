<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Finds the schema.org Recipe in a web page's JSON-LD and prepares it for
 * SchemaOrgRecipeParser. Shared by the Chefkoch import
 * (ChefkochImportService) and the shared-link import (LinkRecipeReader), so
 * both read a recipe page exactly the same way:
 *
 *  - The Recipe node may sit at top level, in a list, in `@graph`, or nested
 *    (e.g. as a WebPage's `mainEntity`); `@type` may be a string or a list,
 *    and may be a full IRI ("http://schema.org/Recipe").
 *  - `image`/`author`/`publisher` given as bare `{"@id": ...}` references
 *    into sibling `@graph` nodes (Chefkoch does this) are resolved inline -
 *    SchemaOrgRecipeParser expects inline values.
 *  - Site boilerplate is dropped: every chefkoch.de page's JSON-LD
 *    `description` is the same auto-generated SEO template (rating count
 *    plus "Mit ► Portionsrechner ► Kochbuch ► Video-Tipps!", live-verified
 *    2026-10-01), never a real author-written subtitle. A title ending in
 *    " von <Name>" still becomes a real description via
 *    RecipeTitleAuthorSplitter regardless. SchemaOrgRecipeParser itself
 *    stays generic (the JSON file import uses it too, where a description
 *    is legitimate data).
 */
final class JsonLdRecipeFinder
{
    public static function find(string $html, string $pageUrl): ?array
    {
        if (preg_match_all('#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks) < 1) {
            return null;
        }
        foreach ($blocks[1] as $block) {
            $json = trim(preg_replace('#^\s*(<!--|//\s*<!\[CDATA\[)|(-->|//\s*\]\]>)\s*$#', '', $block) ?? $block);
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5), true);
            }
            if (!is_array($decoded)) {
                continue;
            }
            $recipe = self::findRecipeNode($decoded, 0);
            if ($recipe !== null) {
                $recipe = self::resolveGraphReferences($recipe, self::graphNodes($decoded));

                return self::dropSiteBoilerplate($recipe, $pageUrl);
            }
        }

        return null;
    }

    private static function findRecipeNode(array $node, int $depth): ?array
    {
        if ($depth > 6) {
            return null;
        }
        foreach ((array) ($node['@type'] ?? []) as $type) {
            if (is_string($type) && strcasecmp(preg_replace('#^.*[/:]#', '', $type) ?? $type, 'Recipe') === 0) {
                return $node;
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = self::findRecipeNode($child, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array> all nodes of the document's `@graph` (or the
     *         top-level list) that a reference could point at
     */
    private static function graphNodes(array $decoded): array
    {
        $graph = $decoded['@graph'] ?? (array_is_list($decoded) ? $decoded : []);

        return array_values(array_filter(is_array($graph) ? $graph : [], 'is_array'));
    }

    private static function resolveGraphReferences(array $recipeNode, array $graph): array
    {
        $byId = [];
        foreach ($graph as $node) {
            if (isset($node['@id']) && is_string($node['@id'])) {
                $byId[$node['@id']] = $node;
            }
        }

        foreach (['image', 'author', 'publisher'] as $field) {
            $value = $recipeNode[$field] ?? null;
            if (is_array($value) && isset($value['@id']) && count($value) === 1 && isset($byId[$value['@id']])) {
                $recipeNode[$field] = $byId[$value['@id']];
            }
        }

        return $recipeNode;
    }

    private static function dropSiteBoilerplate(array $recipeNode, string $pageUrl): array
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        if ($host === 'chefkoch.de' || str_ends_with($host, '.chefkoch.de')) {
            unset($recipeNode['description']);
        }

        return $recipeNode;
    }
}
