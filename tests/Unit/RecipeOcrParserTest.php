<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\RecipeOcrParser;
use PHPUnit\Framework\TestCase;

/**
 * RecipeOcrParser is pure array-in/array-out (no DB, no HTTP). Two parsing
 * paths are covered: the column-aware split (paragraphs + pageWidth given,
 * modeled on real two-column recipe-card photos - see the class docblock)
 * and the single-stream line-based fallback (no geometry, or the split
 * wasn't confident) that the original version of this class always used.
 */
final class RecipeOcrParserTest extends TestCase
{
    private RecipeOcrParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecipeOcrParser();
    }

    // ---------- single-stream fallback (no paragraph geometry given) ----------

    public function testExtractsNameIngredientsAndStepsFromGermanKeywords(): void
    {
        $result = $this->parser->parse(['text' =>
            "Apfelkuchen\n\nZutaten:\n200 g Mehl\n3 Eier\n1 Prise Salz\n\nZubereitung:\n1. Mehl und Eier verrühren.\n2. Backen bei 180 Grad."
        ]);

        $this->assertSame('Apfelkuchen', $result['name']);
        $this->assertSame(
            [
                ['name' => 'Mehl', 'amount' => 200.0, 'unit' => 'g', 'note' => null, 'is_heading' => false],
                ['name' => 'Eier', 'amount' => 3.0, 'unit' => null, 'note' => null, 'is_heading' => false],
                ['name' => 'Salz', 'amount' => 1.0, 'unit' => 'Prise', 'note' => null, 'is_heading' => false],
            ],
            $result['ingredients']
        );
        $this->assertSame(
            [
                ['instruction' => 'Mehl und Eier verrühren.', 'is_heading' => false],
                ['instruction' => 'Backen bei 180 Grad.', 'is_heading' => false],
            ],
            $result['steps']
        );
        $this->assertNull($result['notes']);
    }

    public function testRecognizesEnglishKeywordsToo(): void
    {
        $result = $this->parser->parse(['text' => "Pancakes\n\nIngredients:\n2 cup flour\n\nInstructions:\nMix everything."]);

        $this->assertSame('Pancakes', $result['name']);
        $this->assertSame('flour', $result['ingredients'][0]['name']);
        $this->assertSame('cup', $result['ingredients'][0]['unit']);
        $this->assertSame('Mix everything.', $result['steps'][0]['instruction']);
    }

    public function testIngredientLineWithCommaDecimalAmountIsNormalized(): void
    {
        $result = $this->parser->parse(['text' => "Kuchen\n\nZutaten:\n1,5 EL Zucker\n\nZubereitung:\nRühren."]);

        $this->assertSame(1.5, $result['ingredients'][0]['amount']);
        $this->assertSame('EL', $result['ingredients'][0]['unit']);
        $this->assertSame('Zucker', $result['ingredients'][0]['name']);
    }

    public function testIngredientLineWithUnrecognizedUnitWordKeepsItInTheName(): void
    {
        $result = $this->parser->parse(['text' => "Suppe\n\nZutaten:\n2 Zwiebeln\n\nZubereitung:\nSchneiden."]);

        $this->assertSame('Zwiebeln', $result['ingredients'][0]['name']);
        $this->assertSame(2.0, $result['ingredients'][0]['amount']);
        $this->assertNull($result['ingredients'][0]['unit']);
    }

    public function testIngredientLineWithNoLeadingNumberIsLeftEntirelyToTheUser(): void
    {
        $result = $this->parser->parse(['text' => "Suppe\n\nZutaten:\nSalz, Pfeffer\n\nZubereitung:\nWürzen."]);

        $this->assertSame(
            ['name' => 'Salz, Pfeffer', 'amount' => null, 'unit' => null, 'note' => null, 'is_heading' => false],
            $result['ingredients'][0]
        );
    }

    public function testStepNumberingPrefixIsStripped(): void
    {
        $result = $this->parser->parse(['text' => "Kuchen\n\nZutaten:\n1 Ei\n\nZubereitung:\n1) Vorbereiten\n2) Backen"]);

        $this->assertSame('Vorbereiten', $result['steps'][0]['instruction']);
        $this->assertSame('Backen', $result['steps'][1]['instruction']);
    }

    /**
     * The bug reported after real-world testing: handwritten instructions
     * wrap across many OCR lines per logical step - one OCR line was never
     * a reliable step boundary, it fragmented a few real sentences into a
     * dozen+ junk "steps". Un-numbered prose is now split on sentence-
     * ending punctuation instead of on every line break.
     */
    public function testWrappedUnnumberedStepLinesAreJoinedIntoSentencesNotOnePerLine(): void
    {
        $result = $this->parser->parse(['text' =>
            "Kuchen\n\nZutaten:\n1 Ei\n\nZubereitung:\nDen ersten Teil verrühren\nund bei 160°C 10-15 min\nbacken. Auskühlen lassen."
        ]);

        $this->assertSame(
            [
                ['instruction' => 'Den ersten Teil verrühren und bei 160°C 10-15 min backen.', 'is_heading' => false],
                ['instruction' => 'Auskühlen lassen.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    public function testNoKeywordsFoundPutsEverythingIntoNotesInstead(): void
    {
        $result = $this->parser->parse(['text' => "Oma's Rezept\n200 g Mehl\nBacken bei 180 Grad"]);

        $this->assertNull($result['name']);
        $this->assertSame([], $result['ingredients']);
        $this->assertSame([], $result['steps']);
        $this->assertSame("Oma's Rezept\n200 g Mehl\nBacken bei 180 Grad", $result['notes']);
    }

    public function testSuspiciouslyLongFirstLineIsNotGuessedAsTheName(): void
    {
        $result = $this->parser->parse(['text' => str_repeat('x', 130) . "\n\nZutaten:\n1 Ei\n\nZubereitung:\nRühren."]);

        $this->assertNull($result['name']);
    }

    public function testBlankInputProducesAnEmptyDraft(): void
    {
        $result = $this->parser->parse(['text' => '']);

        $this->assertNull($result['name']);
        $this->assertSame([], $result['ingredients']);
        $this->assertSame([], $result['steps']);
        $this->assertNull($result['notes']);
    }

    public function testANameLabelPrefixIsStrippedFromThePrintedTemplateHeading(): void
    {
        $result = $this->parser->parse(['text' => "Rezeptname: Apfelkuchen\n\nZutaten:\n1 Ei\n\nZubereitung:\nRühren."]);

        $this->assertSame('Apfelkuchen', $result['name']);
    }

    // ---------- column-aware split (paragraph geometry given) ----------

    /**
     * Modeled on real photos of a printed two-column recipe card template
     * ("Zutaten für ... Personen:" on the left, "Zubereitung:" on the
     * right) - the exact layout that broke the original line-based-only
     * parser (ingredients were never found at all, since the flat OCR text
     * interleaves both columns in an order plain line scanning can't
     * untangle).
     */
    public function testSplitsATwoColumnRecipeCardByParagraphGeometry(): void
    {
        $document = [
            'text' => 'irrelevant when paragraphs/pageWidth are present',
            'pageWidth' => 2000.0,
            'paragraphs' => [
                ['text' => 'Rezeptname: Apfelkuchen', 'xMin' => 100, 'xMax' => 1900, 'yTop' => 50],
                ['text' => 'Zutaten für', 'xMin' => 100, 'xMax' => 400, 'yTop' => 500],
                ['text' => 'Personen:', 'xMin' => 550, 'xMax' => 750, 'yTop' => 505],
                ['text' => "200 g Mehl\n3 Eier", 'xMin' => 100, 'xMax' => 500, 'yTop' => 600],
                ['text' => 'Zubereitung:', 'xMin' => 1000, 'xMax' => 1300, 'yTop' => 500],
                ['text' => "Mehl und Eier verrühren.\nBacken bei 180 Grad.", 'xMin' => 1000, 'xMax' => 1900, 'yTop' => 650],
            ],
        ];

        $result = $this->parser->parse($document);

        $this->assertSame('Apfelkuchen', $result['name']);
        $this->assertSame(
            [
                ['name' => 'Mehl', 'amount' => 200.0, 'unit' => 'g', 'note' => null, 'is_heading' => false],
                ['name' => 'Eier', 'amount' => 3.0, 'unit' => null, 'note' => null, 'is_heading' => false],
            ],
            $result['ingredients']
        );
        $this->assertSame(
            [
                ['instruction' => 'Mehl und Eier verrühren.', 'is_heading' => false],
                ['instruction' => 'Backen bei 180 Grad.', 'is_heading' => false],
            ],
            $result['steps']
        );
    }

    public function testTemplateLabelRemainderOnTheHeadingLineIsNotKeptAsAnIngredient(): void
    {
        $document = [
            'text' => 'irrelevant',
            'pageWidth' => 2000.0,
            'paragraphs' => [
                ['text' => 'Zutaten für', 'xMin' => 100, 'xMax' => 400, 'yTop' => 500],
                ['text' => 'Personen:', 'xMin' => 550, 'xMax' => 750, 'yTop' => 505],
                ['text' => '200 g Mehl', 'xMin' => 100, 'xMax' => 500, 'yTop' => 600],
                ['text' => 'Zubereitung:', 'xMin' => 1000, 'xMax' => 1300, 'yTop' => 500],
                ['text' => 'Rühren.', 'xMin' => 1000, 'xMax' => 1900, 'yTop' => 650],
            ],
        ];

        $result = $this->parser->parse($document);

        // Neither the "für" remainder nor the standalone "Personen:" noise
        // paragraph should show up as a bogus ingredient row.
        $this->assertCount(1, $result['ingredients']);
        $this->assertSame('Mehl', $result['ingredients'][0]['name']);
    }

    public function testAWideParagraphSpanningBothColumnsIsIncludedInBoth(): void
    {
        $document = [
            'text' => 'irrelevant',
            'pageWidth' => 2000.0,
            'paragraphs' => [
                ['text' => 'Zutaten:', 'xMin' => 100, 'xMax' => 400, 'yTop' => 500],
                ['text' => '200 g Mehl', 'xMin' => 100, 'xMax' => 500, 'yTop' => 600],
                ['text' => 'Zubereitung:', 'xMin' => 1000, 'xMax' => 1300, 'yTop' => 500],
                // Spans almost the full page width - a merged two-column
                // OCR line, same as the real "Pinienkerne (ungeröstet)
                // Parmesan darüber reiben." case found in live testing.
                ['text' => 'Zucker Rühren gut durch.', 'xMin' => 80, 'xMax' => 1950, 'yTop' => 700],
            ],
        ];

        $result = $this->parser->parse($document);

        $ingredientNames = array_column($result['ingredients'], 'name');
        $stepInstructions = array_column($result['steps'], 'instruction');
        $this->assertContains('Zucker Rühren gut durch.', $ingredientNames);
        $this->assertContains('Zucker Rühren gut durch.', $stepInstructions);
    }

    public function testFallsBackToSingleStreamWhenBothHeadingsLandOnTheSameSide(): void
    {
        $document = [
            'text' => "Zutaten:\n200 g Mehl\n\nZubereitung:\nRühren.",
            'pageWidth' => 2000.0,
            'paragraphs' => [
                // Both headings on the left - not a confident two-column
                // split, so the caller should fall back to plain line
                // parsing over $document['text'] instead of guessing.
                ['text' => 'Zutaten:', 'xMin' => 100, 'xMax' => 400, 'yTop' => 500],
                ['text' => 'Zubereitung:', 'xMin' => 100, 'xMax' => 400, 'yTop' => 600],
            ],
        ];

        $result = $this->parser->parse($document);

        $this->assertSame('Mehl', $result['ingredients'][0]['name']);
        $this->assertSame('Rühren.', $result['steps'][0]['instruction']);
    }

    public function testFallsBackToSingleStreamWhenNoGeometryIsProvidedAtAll(): void
    {
        $result = $this->parser->parse(['text' => "Zutaten:\n1 Ei\n\nZubereitung:\nRühren."]);

        $this->assertSame('Ei', $result['ingredients'][0]['name']);
        $this->assertSame('Rühren.', $result['steps'][0]['instruction']);
    }
}
