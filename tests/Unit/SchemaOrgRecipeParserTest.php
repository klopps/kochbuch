<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use InvalidArgumentException;
use Kochbuch\Service\SchemaOrgRecipeParser;
use PHPUnit\Framework\TestCase;

/**
 * SchemaOrgRecipeParser is pure array-in/array-out (no DB, no HTTP).
 * Verified against real, user-supplied schema.org Recipe JSON-LD files
 * during development (not just these synthetic cases) - all four parsed
 * cleanly, including the HowToSection-with-headings shape covered here.
 */
final class SchemaOrgRecipeParserTest extends TestCase
{
    private SchemaOrgRecipeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SchemaOrgRecipeParser();
    }

    public function testParsesAMinimalRecipeWithPlainStringIngredientsAndInstructions(): void
    {
        $result = $this->parser->parse([
            'name' => 'Apfelkuchen',
            'recipeIngredient' => ['200 g Mehl', '3 Eier'],
            'recipeInstructions' => ['Mehl und Eier verrühren.', 'Backen bei 180 Grad.'],
        ]);

        $this->assertSame('Apfelkuchen', $result['name']);
        $this->assertSame('Mehl', $result['ingredients'][0]['name']);
        $this->assertSame(200.0, $result['ingredients'][0]['amount']);
        $this->assertSame('g', $result['ingredients'][0]['unit']);
        $this->assertSame(
            [
                ['instruction' => 'Mehl und Eier verrühren.', 'is_heading' => false],
                ['instruction' => 'Backen bei 180 Grad.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    public function testParsesHowToStepObjectInstructions(): void
    {
        $result = $this->parser->parse([
            'name' => 'Marzipan',
            'recipeIngredient' => ['250 g gemahlene Mandeln'],
            'recipeInstructions' => [
                ['@type' => 'HowToStep', 'text' => 'Alle Zutaten zusammen vermengen.'],
                ['@type' => 'HowToStep', 'text' => 'Kleine Kugeln formen.'],
            ],
        ]);

        $this->assertSame(
            [
                ['instruction' => 'Alle Zutaten zusammen vermengen.', 'is_heading' => false],
                ['instruction' => 'Kleine Kugeln formen.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    /**
     * The real shape found in one of the user's own recipe files: sections
     * ("Teig", "Füllung", ...) each becoming a heading row, followed by
     * their own steps - Kochbuch's step editor already supports heading
     * rows (the "Add section" button), so this maps directly onto it.
     */
    public function testFlattensNestedHowToSectionsIntoHeadingRowsFollowedByTheirSteps(): void
    {
        $result = $this->parser->parse([
            'name' => 'Weihnachtskekse',
            'recipeIngredient' => ['200 g Butter'],
            'recipeInstructions' => [
                [
                    '@type' => 'HowToSection',
                    'name' => 'Teig',
                    'itemListElement' => [
                        ['@type' => 'HowToStep', 'text' => 'Butter und Zucker schaumig rühren.'],
                        ['@type' => 'HowToStep', 'text' => 'Den Teig ausrollen.'],
                    ],
                ],
                [
                    '@type' => 'HowToSection',
                    'name' => 'Füllung',
                    'itemListElement' => [
                        ['@type' => 'HowToStep', 'text' => 'Kakao und Butter verrühren.'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                ['instruction' => 'Teig', 'is_heading' => true],
                ['instruction' => 'Butter und Zucker schaumig rühren.', 'is_heading' => false],
                ['instruction' => 'Den Teig ausrollen.', 'is_heading' => false],
                ['instruction' => 'Füllung', 'is_heading' => true],
                ['instruction' => 'Kakao und Butter verrühren.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    public function testASingleInstructionsStringIsSplitOnNewlines(): void
    {
        $result = $this->parser->parse([
            'name' => 'Suppe',
            'recipeInstructions' => "Gemüse schneiden.\nKochen.",
        ]);

        $this->assertSame(
            [
                ['instruction' => 'Gemüse schneiden.', 'is_heading' => false],
                ['instruction' => 'Kochen.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->parse(['recipeIngredient' => ['1 Ei']]);
    }

    public function testBlankNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->parse(['name' => '   ']);
    }

    public function testRecipeYieldExtractsTheLeadingNumberFromFreetext(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'recipeYield' => '1 Blech']);
        $this->assertSame(1, $result['servings']);

        $result = $this->parser->parse(['name' => 'Kuchen', 'recipeYield' => 4]);
        $this->assertSame(4, $result['servings']);

        $result = $this->parser->parse(['name' => 'Kuchen', 'recipeYield' => 'nicht angegeben']);
        $this->assertNull($result['servings']);

        $result = $this->parser->parse(['name' => 'Kuchen', 'recipeYield' => ['4 servings', '4']]);
        $this->assertSame(4, $result['servings']);
    }

    public function testIso8601DurationsAreConvertedToMinutes(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'prepTime' => 'PT15M', 'cookTime' => 'PT1H30M']);

        $this->assertSame(15, $result['prep_time_minutes']);
        $this->assertSame(90, $result['cook_time_minutes']);
    }

    public function testAnInvalidDurationIsIgnoredRatherThanFailing(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'cookTime' => 'not-a-duration']);

        $this->assertNull($result['cook_time_minutes']);
    }

    public function testRestTimeIsDerivedFromTheGapBetweenTotalAndPrepPlusCook(): void
    {
        $result = $this->parser->parse([
            'name' => 'Brot',
            'prepTime' => 'PT20M',
            'cookTime' => 'PT40M',
            'totalTime' => 'PT2H',
        ]);

        $this->assertSame(20, $result['prep_time_minutes']);
        $this->assertSame(40, $result['cook_time_minutes']);
        $this->assertSame(60, $result['rest_time_minutes']);
    }

    public function testCaloriesAreExtractedFromNutrition(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'nutrition' => ['calories' => '250 kcal']]);

        $this->assertSame(250, $result['calories']);
    }

    public function testSuitableForDietSetsTheMatchingFlags(): void
    {
        $result = $this->parser->parse(['name' => 'Salat', 'suitableForDiet' => 'https://schema.org/VeganDiet']);
        $this->assertTrue($result['is_vegan']);
        $this->assertFalse($result['is_vegetarian']);

        // Ambiguous source data (both tags present) resolves to the most
        // restrictive diet rather than setting both flags - vegan/
        // vegetarian/pescetarian are mutually exclusive on a recipe.
        $result = $this->parser->parse(['name' => 'Salat', 'suitableForDiet' => ['https://schema.org/VegetarianDiet', 'https://schema.org/PescetarianDiet']]);
        $this->assertTrue($result['is_vegetarian']);
        $this->assertFalse($result['is_pescetarian']);
        $this->assertFalse($result['is_vegan']);
    }

    public function testTagsAreMergedFromKeywordsAndRecipeCategory(): void
    {
        $result = $this->parser->parse(['name' => 'Kekse', 'keywords' => 'weihnachten, süß', 'recipeCategory' => 'Gebäck']);

        $this->assertSame(['weihnachten', 'süß', 'Gebäck'], $result['tags']);
    }

    public function testImageUrlHandlesAllRealWorldShapes(): void
    {
        $this->assertSame('https://example.com/a.jpg', $this->parser->parse(['name' => 'A', 'image' => 'https://example.com/a.jpg'])['image_url']);
        $this->assertSame('https://example.com/b.jpg', $this->parser->parse(['name' => 'B', 'image' => ['https://example.com/b.jpg']])['image_url']);
        $this->assertSame('https://example.com/c.jpg', $this->parser->parse(['name' => 'C', 'image' => ['@type' => 'ImageObject', 'url' => 'https://example.com/c.jpg']])['image_url']);
        $this->assertNull($this->parser->parse(['name' => 'D'])['image_url']);
    }

    public function testSourceIsExtractedFromAuthorNameOrPlainString(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'author' => ['@type' => 'Person', 'name' => 'Oma Thea']]);
        $this->assertSame('Oma Thea', $result['source']);

        $result = $this->parser->parse(['name' => 'Kuchen', 'author' => 'Chefkoch.de']);
        $this->assertSame('Chefkoch.de', $result['source']);
    }

    public function testSourceUrlComesFromUrl(): void
    {
        $result = $this->parser->parse(['name' => 'Kuchen', 'url' => 'https://example.com/recipe/42']);

        $this->assertSame('https://example.com/recipe/42', $result['source_url']);
    }

    /**
     * todo.md "Import aus Kochbuch von Chefkoch.de" - the Recipe node from a
     * real chefkoch.de recipe page's JSON-LD, captured live while planning
     * that feature (see ChefkochImportService's doc-comment). `image`/
     * `author` are given here already resolved to inline values, exactly as
     * ChefkochImportService::resolveGraphReferences() produces them from the
     * page's original `{"@id": "..."}`-only references - this test only
     * covers that the existing, generic parser handles the real field
     * shapes correctly (freetext nutrition.calories, a two-element
     * recipeYield array, a single HowToSection, a single suitableForDiet
     * string), not the graph-reference resolution itself.
     */
    public function testParsesARealChefkochRecipeNode(): void
    {
        $result = $this->parser->parse([
            '@type' => 'Recipe',
            'name' => 'Brokkoli indisch von harrdie',
            'recipeCategory' => 'Gemüse',
            'author' => ['@type' => 'Person', 'name' => 'harrdie'],
            'image' => ['@type' => 'ImageObject', 'url' => 'https://img.chefkoch-cdn.de/brokkoli.jpg'],
            'prepTime' => 'PT10M',
            'cookTime' => 'PT25M',
            'totalTime' => 'PT35M',
            'recipeYield' => ['4', '4 Portionen'],
            'keywords' => 'Vegetarisch, Beilage, einfach, Dünsten',
            'recipeInstructions' => [
                [
                    '@type' => 'HowToSection',
                    'name' => 'Zubereitung',
                    'itemListElement' => [
                        ['@type' => 'HowToStep', 'position' => 1, 'text' => 'Die Brokkolröschen vom Strunk ablösen.'],
                        ['@type' => 'HowToStep', 'position' => 2, 'text' => 'Öl und Butter erhitzen, Brokkoli anbraten.'],
                    ],
                ],
            ],
            'recipeIngredient' => [
                '500 g Brokkoli', '3 EL Öl', '20 g Butter', '100 ml Kokosmilch',
                '1 EL Kichererbsenmehl', '0.5 Zehe/n Knoblauch', '1 TL Curry',
                '0.5 TL Kreuzkümmel', '200 ml Gemüsebrühe', 'Salz',
            ],
            'nutrition' => ['@type' => 'NutritionInformation', 'calories' => '177 kcal'],
            'suitableForDiet' => 'https://schema.org/VegetarianDiet',
        ]);

        $this->assertSame('Brokkoli indisch von harrdie', $result['name']);
        $this->assertSame(10, $result['prep_time_minutes']);
        $this->assertSame(25, $result['cook_time_minutes']);
        // totalTime (35) exactly equals prep+cook (10+25) here - no positive
        // remainder, so rest_time_minutes correctly stays null rather than 0.
        $this->assertNull($result['rest_time_minutes']);
        $this->assertSame(4, $result['servings']);
        $this->assertSame(177, $result['calories']);
        $this->assertTrue($result['is_vegetarian']);
        $this->assertFalse($result['is_vegan']);
        $this->assertSame(['Vegetarisch', 'Beilage', 'einfach', 'Dünsten', 'Gemüse'], $result['tags']);
        $this->assertSame('harrdie', $result['source']);
        $this->assertSame('https://img.chefkoch-cdn.de/brokkoli.jpg', $result['image_url']);
        $this->assertCount(10, $result['ingredients']);
        $this->assertSame('Brokkoli', $result['ingredients'][0]['name']);
        $this->assertSame(500.0, $result['ingredients'][0]['amount']);
        $this->assertSame('g', $result['ingredients'][0]['unit']);
        // The last line has no leading amount at all - stays a plain name.
        $this->assertSame('Salz', $result['ingredients'][9]['name']);
        $this->assertNull($result['ingredients'][9]['amount']);
        // HowToSection's own name becomes a heading row, followed by its two steps.
        $this->assertCount(3, $result['steps']);
        $this->assertTrue($result['steps'][0]['is_heading']);
        $this->assertSame('Zubereitung', $result['steps'][0]['instruction']);
        $this->assertFalse($result['steps'][1]['is_heading']);
    }
}
