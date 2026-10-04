<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\IngredientLineParser;
use Kochbuch\Service\UnitNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * EL/TL are always uppercase in imported recipes, whatever the source wrote.
 */
final class UnitNormalizerTest extends TestCase
{
    public function testUppercasesElAndTlInAnySpelling(): void
    {
        foreach (['el', 'El', 'eL', 'EL', 'el.', 'El.', ' el '] as $spelling) {
            $this->assertSame('EL', UnitNormalizer::normalize($spelling), $spelling);
        }
        foreach (['tl', 'Tl', 'tl.', 'TL'] as $spelling) {
            $this->assertSame('TL', UnitNormalizer::normalize($spelling), $spelling);
        }
    }

    public function testLeavesOtherUnitsAloneAndEmptyBecomesNull(): void
    {
        $this->assertSame('g', UnitNormalizer::normalize('g'));
        $this->assertSame('Prise', UnitNormalizer::normalize('Prise'));
        $this->assertSame('Stk.', UnitNormalizer::normalize('Stk.'));
        $this->assertSame('ml', UnitNormalizer::normalize(' ml '));
        $this->assertNull(UnitNormalizer::normalize(''));
        $this->assertNull(UnitNormalizer::normalize(null));
    }

    public function testIngredientLinesGetUppercaseSpoonUnits(): void
    {
        $parser = new IngredientLineParser();

        $this->assertSame('EL', $parser->parse('2 el Olivenöl')['unit']);
        $this->assertSame('TL', $parser->parse('1 tl. Salz')['unit']);
        $this->assertSame('TL', $parser->parse('1tl Zucker')['unit']);
        $this->assertSame('g', $parser->parse('200 g Mehl')['unit']);
    }
}
