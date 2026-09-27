<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\IngredientLineParser;
use PHPUnit\Framework\TestCase;

/**
 * IngredientLineParser is pure string-in/array-out (no DB, no HTTP) -
 * shared by RecipeOcrParser (photo OCR) and SchemaOrgRecipeParser (JSON-LD
 * import). todo.md "Improving Photo Recognition": recognized ingredient
 * text frequently glues the unit directly onto the quantity ("150g"
 * instead of "150 g") - every standard unit from that report is covered
 * here, both glued and space-separated, to make sure both forms parse
 * identically.
 */
final class IngredientLineParserTest extends TestCase
{
    private IngredientLineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IngredientLineParser();
    }

    /**
     * @dataProvider unitProvider
     */
    public function testGluedQuantityAndUnitParsesTheSameAsSpaceSeparated(string $unit): void
    {
        $glued = $this->parser->parse('150' . $unit . ' Mehl');
        $spaced = $this->parser->parse('150 ' . $unit . ' Mehl');

        $expected = ['name' => 'Mehl', 'amount' => 150.0, 'unit' => $unit, 'note' => null, 'is_heading' => false];
        $this->assertSame($expected, $glued);
        $this->assertSame($expected, $spaced);
    }

    public static function unitProvider(): array
    {
        // Exactly the unit list from the todo.md report (Stk./Stück/Prise
        // capitalized as a user would actually write them - matching is
        // case-insensitive).
        return array_map(
            static fn (string $u) => [$u],
            ['g', 'kg', 'l', 'ml', 'cl', 'dl', 'TL', 'EL', 'Stk.', 'Stück', 'Prise']
        );
    }

    public function testGluedDecimalAmountWithCommaAlsoWorks(): void
    {
        $result = $this->parser->parse('1,5EL Öl');

        $this->assertSame(1.5, $result['amount']);
        $this->assertSame('EL', $result['unit']);
        $this->assertSame('Öl', $result['name']);
    }

    public function testGluedUnrecognizedWordIsKeptAsPartOfTheName(): void
    {
        $result = $this->parser->parse('4Eier');

        $this->assertSame(['name' => 'Eier', 'amount' => 4.0, 'unit' => null, 'note' => null, 'is_heading' => false], $result);
    }

    public function testNoLeadingNumberIsLeftEntirelyToTheUser(): void
    {
        $result = $this->parser->parse('Salz, Pfeffer');

        $this->assertSame(['name' => 'Salz, Pfeffer', 'amount' => null, 'unit' => null, 'note' => null, 'is_heading' => false], $result);
    }

    public function testAGluedUnitWithNoNameAfterwardsIsNotTreatedAsAnEmptyIngredient(): void
    {
        $result = $this->parser->parse('150g');

        $this->assertSame('g', $result['name']);
        $this->assertSame(150.0, $result['amount']);
        $this->assertNull($result['unit']);
    }
}
