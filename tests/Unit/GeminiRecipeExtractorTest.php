<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Exception\ApiException;
use Kochbuch\Service\GeminiRecipeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * GeminiRecipeExtractor is a plain HTTP wrapper (no DB) - the injected fake
 * sender means these tests never hit the real, key-authenticated Gemini API.
 */
final class GeminiRecipeExtractorTest extends TestCase
{
    private function reply(array $recipe): array
    {
        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($recipe)]]]]]])];
    }

    public function testMapsTheModelsJsonOntoTheDraftShape(): void
    {
        $captured = null;
        $extractor = new GeminiRecipeExtractor('fake-key', 'gemini-test', function (string $url, array $payload, string $key) use (&$captured) {
            $captured = [$url, $payload, $key];

            return $this->reply([
                'name' => ' Apfelkuchen ',
                'ingredients' => [
                    ['name' => 'Für den Teig', 'amount' => null, 'unit' => null, 'note' => null, 'is_heading' => true],
                    ['name' => 'Mehl', 'amount' => 200, 'unit' => 'g', 'note' => 'gesiebt', 'is_heading' => false],
                    ['name' => 'Salz', 'amount' => null, 'unit' => '', 'note' => null, 'is_heading' => false],
                ],
                'steps' => [['instruction' => 'Alles verrühren.', 'is_heading' => false]],
                'notes' => null,
                'raw_text' => "Apfelkuchen\n200 g Mehl",
            ]);
        });

        $draft = $extractor->extract('imagebytes', 'image/jpeg');

        $this->assertSame('Apfelkuchen', $draft['name']);
        $this->assertTrue($draft['ingredients'][0]['is_heading']);
        $this->assertSame(['name' => 'Mehl', 'amount' => 200.0, 'unit' => 'g', 'note' => 'gesiebt', 'is_heading' => false], $draft['ingredients'][1]);
        $this->assertNull($draft['ingredients'][2]['unit']);
        $this->assertSame('Alles verrühren.', $draft['steps'][0]['instruction']);
        $this->assertNull($draft['notes']);
        $this->assertSame("Apfelkuchen\n200 g Mehl", $draft['raw_text']);

        $this->assertStringContainsString('models/gemini-test:generateContent', $captured[0]);
        $this->assertSame('fake-key', $captured[2]);
        $this->assertSame(base64_encode('imagebytes'), $captured[1]['contents'][0]['parts'][1]['inline_data']['data']);
    }

    public function testDropsMalformedRowsInsteadOfFailing(): void
    {
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => $this->reply([
            'ingredients' => ['nonsense', ['name' => '  '], ['name' => 'Ei', 'amount' => 'viele', 'is_heading' => false]],
            'steps' => [['instruction' => ''], 5],
        ]));

        $draft = $extractor->extract('x', 'image/png');

        $this->assertSame(['Ei'], array_column($draft['ingredients'], 'name'));
        $this->assertNull($draft['ingredients'][0]['amount']);
        $this->assertSame([], $draft['steps']);
        $this->assertNull($draft['name']);
    }

    public function testFallsBackToTheNextModelWhenOneIsOverloadedOrRetired(): void
    {
        $urls = [];
        $extractor = new GeminiRecipeExtractor('k', 'a, b ,c', function (string $url) use (&$urls) {
            $urls[] = $url;
            if (count($urls) === 1) {
                return ['status' => 503, 'body' => 'busy'];
            }
            if (count($urls) === 2) {
                return ['status' => 404, 'body' => 'gone'];
            }

            return $this->reply(['name' => 'Ok', 'ingredients' => [], 'steps' => [], 'raw_text' => 'x']);
        });

        $draft = $extractor->extract('x', 'image/png');

        $this->assertSame('Ok', $draft['name']);
        $this->assertCount(3, $urls);
        $this->assertStringContainsString('models/c:', $urls[2]);
    }

    public function testStopsEarlyOnAClientErrorThatWouldFailOnEveryModel(): void
    {
        $calls = 0;
        $extractor = new GeminiRecipeExtractor('k', 'a,b', function () use (&$calls) {
            $calls++;

            return ['status' => 400, 'body' => 'bad key'];
        });

        try {
            $extractor->extract('x', 'image/png');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(1, $calls);
        }
    }

    public function testStripsAnAmountThatTheModelLeftInTheIngredientName(): void
    {
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => $this->reply([
            'ingredients' => [
                ['name' => '1/3 Bio-Gurke', 'amount' => 0.33, 'unit' => null, 'note' => null, 'is_heading' => false],
                ['name' => '1 1/2 Zwiebeln', 'amount' => 1.5, 'unit' => null, 'note' => null, 'is_heading' => false],
                ['name' => '2 Eier', 'amount' => 2, 'unit' => null, 'note' => null, 'is_heading' => false],
                ['name' => '7-Kräuter-Mix', 'amount' => 1, 'unit' => 'Pck.', 'note' => null, 'is_heading' => false],
                ['name' => '3 Eier', 'amount' => 2, 'unit' => null, 'note' => null, 'is_heading' => false],
                ['name' => '200', 'amount' => 200, 'unit' => 'g', 'note' => null, 'is_heading' => false],
            ],
            'steps' => [],
        ]));

        $names = array_column($extractor->extract('x', 'image/png')['ingredients'], 'name');

        $this->assertSame(['Bio-Gurke', 'Zwiebeln', 'Eier', '7-Kräuter-Mix', '3 Eier', '200'], $names);
    }

    public function testSnapsRoundedFractionsToTheirExactValue(): void
    {
        $amounts = [0.33, 0.67, 0.17, 0.25, 0.5, 1.5, 2.33, 0.3, 200, 0.75, 0.125];
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => $this->reply([
            'ingredients' => array_map(fn ($a) => ['name' => 'X', 'amount' => $a, 'unit' => null, 'note' => null, 'is_heading' => false], $amounts),
            'steps' => [],
        ]));

        $result = array_column($extractor->extract('x', 'image/png')['ingredients'], 'amount');

        $this->assertEqualsWithDelta([0.333333, 0.666667, 0.166667, 0.25, 0.5, 1.5, 2.333333, 0.3, 200, 0.75, 0.125], $result, 0.0000001);
    }

    public function testExtractTextSendsTheTextAsATextOnlyRequest(): void
    {
        $payload = null;
        $extractor = new GeminiRecipeExtractor('k', 'm', function (string $url, array $p) use (&$payload) {
            $payload = $p;

            return $this->reply(['name' => 'Gurkensalat', 'ingredients' => [['name' => 'Gurke', 'amount' => 1, 'is_heading' => false]], 'steps' => [], 'raw_text' => 'x']);
        });

        $draft = $extractor->extractText('1 Gurke schneiden #foodie');

        $this->assertSame('Gurkensalat', $draft['name']);
        $parts = $payload['contents'][0]['parts'];
        $this->assertCount(1, $parts);
        $this->assertStringContainsString('1 Gurke schneiden #foodie', $parts[0]['text']);
        $this->assertArrayNotHasKey('inline_data', $parts[0]);
    }

    public function testSpoonUnitsAreAlwaysUppercase(): void
    {
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => $this->reply([
            'ingredients' => [
                ['name' => 'Öl', 'amount' => 2, 'unit' => 'el', 'note' => null, 'is_heading' => false],
                ['name' => 'Salz', 'amount' => 1, 'unit' => 'Tl.', 'note' => null, 'is_heading' => false],
            ],
            'steps' => [],
        ]));

        $this->assertSame(['EL', 'TL'], array_column($extractor->extract('x', 'image/png')['ingredients'], 'unit'));
    }

    public function testHttpErrorBecomesOcrUnavailable(): void
    {
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => ['status' => 429, 'body' => 'quota']);

        try {
            $extractor->extract('x', 'image/png');
            $this->fail('Expected a recipe.ocr_unavailable ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('recipe.ocr_unavailable', $e->getErrorCode());
        }
    }

    public function testNonJsonAnswerBecomesOcrUnavailable(): void
    {
        $extractor = new GeminiRecipeExtractor('k', 'm', fn () => ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'sorry']]]]]])]);

        $this->expectException(ApiException::class);
        $extractor->extract('x', 'image/png');
    }
}
