<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\RecipeTitleAuthorSplitter;
use PHPUnit\Framework\TestCase;

final class RecipeTitleAuthorSplitterTest extends TestCase
{
    public function testStripsATrailingVonNameSuffixIntoANewDescriptionNote(): void
    {
        $result = RecipeTitleAuthorSplitter::split('Kartoffelsalat von maxmuster123', null);

        $this->assertSame('Kartoffelsalat', $result['name']);
        $this->assertSame('Rezept von maxmuster123', $result['description']);
    }

    public function testAppendsTheNoteAfterAnExistingDescriptionRatherThanReplacingIt(): void
    {
        $result = RecipeTitleAuthorSplitter::split('Kartoffelsalat von maxmuster123', 'Ein Familienrezept.');

        $this->assertSame('Kartoffelsalat', $result['name']);
        $this->assertSame("Ein Familienrezept.\n\nRezept von maxmuster123", $result['description']);
    }

    /**
     * Only the LAST " von <Name>" segment is stripped - "von" is also an
     * ordinary German word that legitimately appears earlier in a dish
     * name (e.g. "made of/from"), so it must survive as part of the title.
     */
    public function testOnlyTheTrailingSegmentIsStrippedWhenVonAppearsEarlierToo(): void
    {
        $result = RecipeTitleAuthorSplitter::split('Consomme von Tomate von maxmuster123', null);

        $this->assertSame('Consomme von Tomate', $result['name']);
        $this->assertSame('Rezept von maxmuster123', $result['description']);
    }

    public function testATitleWithNoVonAtAllIsLeftUntouched(): void
    {
        $result = RecipeTitleAuthorSplitter::split('Kartoffelsalat', 'Eine Vorspeise.');

        $this->assertSame('Kartoffelsalat', $result['name']);
        $this->assertSame('Eine Vorspeise.', $result['description']);
    }
}
