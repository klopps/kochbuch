<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\JsonLdRecipeFinder;
use PHPUnit\Framework\TestCase;

/**
 * JsonLdRecipeFinder - shared by the Chefkoch import and the shared-link
 * import, so both read a recipe page the same way.
 */
final class JsonLdRecipeFinderTest extends TestCase
{
    private static function page(array $jsonLd): string
    {
        return '<html><head><script type="application/ld+json">' . json_encode($jsonLd) . '</script></head></html>';
    }

    public function testFindsARecipeNestedAsMainEntityWithAnIriType(): void
    {
        $html = self::page(['@type' => 'WebPage', 'mainEntity' => ['@type' => 'http://schema.org/Recipe', 'name' => 'Pfannkuchen']]);

        $this->assertSame('Pfannkuchen', JsonLdRecipeFinder::find($html, 'https://example.com/a')['name']);
        $this->assertNull(JsonLdRecipeFinder::find(self::page(['@type' => 'Organization']), 'https://example.com/'));
    }

    public function testResolvesIdReferencesIntoSiblingGraphNodes(): void
    {
        $html = self::page(['@graph' => [
            ['@type' => 'Recipe', 'name' => 'Linsensuppe', 'author' => ['@id' => '#autor'], 'image' => ['@id' => '#bild']],
            ['@id' => '#autor', '@type' => 'Person', 'name' => 'Erika'],
            ['@id' => '#bild', '@type' => 'ImageObject', 'url' => 'https://img.example.com/l.jpg'],
        ]]);

        $recipe = JsonLdRecipeFinder::find($html, 'https://example.com/linsensuppe');

        $this->assertSame('Erika', $recipe['author']['name']);
        $this->assertSame('https://img.example.com/l.jpg', $recipe['image']['url']);
    }

    public function testDropsChefkochsSeoDescriptionButKeepsOtherSitesDescriptions(): void
    {
        $html = self::page(['@type' => 'Recipe', 'name' => 'Ananasbraten',
            'description' => 'Ananasbraten. Über 86 Bewertungen und für ausgezeichnet befunden. Mit ► Portionsrechner ► Kochbuch ► Video-Tipps!']);

        $this->assertArrayNotHasKey('description', JsonLdRecipeFinder::find($html, 'https://www.chefkoch.de/rezepte/1/Ananasbraten.html'));
        $this->assertArrayHasKey('description', JsonLdRecipeFinder::find($html, 'https://www.example.com/ananasbraten'));
    }
}
