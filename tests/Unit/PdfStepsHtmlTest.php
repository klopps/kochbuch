<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Http\Controllers\RecipeController;
use PHPUnit\Framework\TestCase;

/**
 * pdfStepsHtml() is pure formatting (no DB, no controller instance) - a
 * heading must not consume a step number, and numbering must continue
 * across a heading rather than restart.
 */
final class PdfStepsHtmlTest extends TestCase
{
    public function testStepsWithoutHeadingsAreOneOrderedList(): void
    {
        $html = RecipeController::pdfStepsHtml([
            ['instruction' => 'Chop.', 'is_heading' => false],
            ['instruction' => 'Cook.', 'is_heading' => false],
        ]);

        $this->assertSame('<ol start="1"><li>Chop.</li><li>Cook.</li></ol>', $html);
    }

    public function testHeadingSplitsTheListButNumberingContinuesAfterIt(): void
    {
        $html = RecipeController::pdfStepsHtml([
            ['instruction' => 'Chop.', 'is_heading' => false],
            ['instruction' => 'Sauce', 'is_heading' => true],
            ['instruction' => 'Simmer.', 'is_heading' => false],
        ]);

        $this->assertSame(
            '<ol start="1"><li>Chop.</li></ol><p style="font-weight:bold;margin:0.75em 0 0.25em;">Sauce</p><ol start="2"><li>Simmer.</li></ol>',
            $html
        );
    }

    public function testLeadingHeadingProducesNoEmptyListBeforeIt(): void
    {
        $html = RecipeController::pdfStepsHtml([
            ['instruction' => 'Sauce', 'is_heading' => true],
            ['instruction' => 'Simmer.', 'is_heading' => false],
        ]);

        $this->assertSame('<p style="font-weight:bold;margin:0.75em 0 0.25em;">Sauce</p><ol start="1"><li>Simmer.</li></ol>', $html);
    }
}
