<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\RecipeNameCase;
use Kochbuch\Service\RecipeOcrParser;
use Kochbuch\Service\SchemaOrgRecipeParser;
use PHPUnit\Framework\TestCase;

/**
 * All-caps recipe names (printed cards, photos, some websites) are turned
 * into normal capitalization on import; anything else stays untouched.
 */
final class RecipeNameCaseTest extends TestCase
{
    public function testCapitalizesWordsButKeepsConnectingWordsLowercase(): void
    {
        $this->assertSame('Apfelkuchen mit Streuseln und Sahne', RecipeNameCase::fix('APFELKUCHEN MIT STREUSELN UND SAHNE'));
        $this->assertSame('Spaghetti alla Carbonara', RecipeNameCase::fix('SPAGHETTI ALLA CARBONARA'));
        $this->assertSame('Käsespätzle vom Blech', RecipeNameCase::fix('KÄSESPÄTZLE VOM BLECH'));
        $this->assertSame('Chili-Sin-Carne', RecipeNameCase::fix('CHILI-SIN-CARNE'));
    }

    public function testFirstWordIsAlwaysCapitalized(): void
    {
        $this->assertSame('Mit Liebe Gebacken', RecipeNameCase::fix('MIT LIEBE GEBACKEN'));
        $this->assertSame('Die Beste Lasagne', RecipeNameCase::fix('DIE BESTE LASAGNE'));
    }

    public function testKeepsAbbreviations(): void
    {
        $this->assertSame('BBQ-Rippchen', RecipeNameCase::fix('BBQ-RIPPCHEN'));
        $this->assertSame('XXL Pizza für 4', RecipeNameCase::fix('XXL PIZZA FÜR 4'));
    }

    public function testLeavesNamesWithLowercaseLettersAndShortOnesAlone(): void
    {
        $this->assertSame('Omas schneller Apfelkuchen', RecipeNameCase::fix('Omas schneller Apfelkuchen'));
        $this->assertSame('Pasta ALLA NORMA', RecipeNameCase::fix('Pasta ALLA NORMA'));
        $this->assertSame('EI', RecipeNameCase::fix('EI'));
        $this->assertNull(RecipeNameCase::fix(null));
    }

    public function testImportParsersApplyIt(): void
    {
        $this->assertSame('Linsensuppe mit Speck', (new SchemaOrgRecipeParser())->parse(['name' => 'LINSENSUPPE MIT SPECK'])['name']);
        $this->assertSame('Gurkensalat', (new RecipeOcrParser())->parse(['text' => "GURKENSALAT\nZutaten:\n1 Gurke\nZubereitung:\nSchneiden."])['name']);
    }
}
