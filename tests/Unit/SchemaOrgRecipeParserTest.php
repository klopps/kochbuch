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

        $result = $this->parser->parse(['name' => 'Salat', 'suitableForDiet' => ['https://schema.org/VegetarianDiet', 'https://schema.org/PescetarianDiet']]);
        $this->assertTrue($result['is_vegetarian']);
        $this->assertTrue($result['is_pescetarian']);
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
}
