<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Http\Controllers\RecipeController;
use PHPUnit\Framework\TestCase;

/**
 * pdfIngredientLine() is pure formatting (no DB, no controller instance) -
 * order must be amount, unit, name, then the note in parentheses.
 */
final class PdfIngredientLineTest extends TestCase
{
    public function testFullLineIsAmountUnitNameThenNoteInParentheses(): void
    {
        $line = RecipeController::pdfIngredientLine(['amount' => 250, 'unit' => 'g', 'name' => 'Mehl', 'note' => 'Type 405']);

        $this->assertSame('250 g Mehl (Type 405)', $line);
    }

    public function testDecimalAmountUsesGermanCommaWithoutTrailingZeros(): void
    {
        $line = RecipeController::pdfIngredientLine(['amount' => 1.5, 'unit' => 'EL', 'name' => 'Öl', 'note' => null]);

        $this->assertSame('1,5 EL Öl', $line);
    }

    public function testMissingPartsLeaveNoDoubleSpacesOrEmptyParentheses(): void
    {
        $this->assertSame('2 Eier', RecipeController::pdfIngredientLine(['amount' => 2, 'unit' => null, 'name' => 'Eier', 'note' => '']));
        $this->assertSame('Salz (nach Geschmack)', RecipeController::pdfIngredientLine(['amount' => null, 'unit' => '', 'name' => 'Salz', 'note' => 'nach Geschmack']));
    }
}
