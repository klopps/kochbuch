<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Exception\ApiException;
use Kochbuch\Service\VisionOcrService;
use PHPUnit\Framework\TestCase;

/**
 * VisionOcrService is a plain HTTP wrapper (no DB) - the injected fake
 * sender means these tests never hit the real, external, key-authenticated
 * Vision API. Focuses on recognizeDocument()'s paragraph/bounding-box
 * extraction, the non-trivial part added to fix real-world OCR parsing
 * (RecipeOcrParser needs each paragraph's geometry to tell a recipe card's
 * two printed columns apart - see that class's docblock).
 */
final class VisionOcrServiceTest extends TestCase
{
    private function service(callable $sender): VisionOcrService
    {
        return new VisionOcrService('fake-key', $sender);
    }

    public function testExtractsTextAndParagraphGeometryFromAWellFormedResponse(): void
    {
        $service = $this->service(fn () => ['status' => 200, 'body' => json_encode([
            'responses' => [[
                'fullTextAnnotation' => [
                    'text' => "Zutaten:\n200 g Mehl",
                    'pages' => [[
                        'width' => 2000,
                        'blocks' => [[
                            'paragraphs' => [[
                                'boundingBox' => ['vertices' => [['x' => 100, 'y' => 50], ['x' => 500, 'y' => 50], ['x' => 500, 'y' => 150], ['x' => 100, 'y' => 150]]],
                                'words' => [
                                    [
                                        'symbols' => [
                                            ['text' => 'Z', 'property' => []],
                                            ['text' => 'u', 'property' => []],
                                            ['text' => 't', 'property' => ['detectedBreak' => ['type' => 'EOL_SURE_SPACE']]],
                                        ],
                                    ],
                                    [
                                        'symbols' => [
                                            ['text' => '2', 'property' => []],
                                            ['text' => '0', 'property' => ['detectedBreak' => ['type' => 'SPACE']]],
                                        ],
                                    ],
                                    [
                                        'symbols' => [
                                            ['text' => 'g', 'property' => []],
                                        ],
                                    ],
                                ],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ])]);

        $document = $service->recognizeDocument('fake-image-bytes');

        $this->assertSame("Zutaten:\n200 g Mehl", $document['text']);
        $this->assertSame(2000.0, $document['pageWidth']);
        $this->assertCount(1, $document['paragraphs']);
        $this->assertSame("Zut\n20 g", $document['paragraphs'][0]['text']);
        $this->assertSame(100.0, $document['paragraphs'][0]['xMin']);
        $this->assertSame(500.0, $document['paragraphs'][0]['xMax']);
        $this->assertSame(50.0, $document['paragraphs'][0]['yTop']);
    }

    public function testMissingPagesProducesEmptyParagraphsAndZeroPageWidth(): void
    {
        $service = $this->service(fn () => ['status' => 200, 'body' => json_encode([
            'responses' => [['fullTextAnnotation' => ['text' => 'Some text']]],
        ])]);

        $document = $service->recognizeDocument('fake-image-bytes');

        $this->assertSame('Some text', $document['text']);
        $this->assertSame([], $document['paragraphs']);
        $this->assertSame(0.0, $document['pageWidth']);
    }

    public function testAVertexWithAnOmittedZeroCoordinateDoesNotCrashExtraction(): void
    {
        // Vision omits a vertex's "x"/"y" key entirely rather than sending
        // an explicit 0 for it.
        $service = $this->service(fn () => ['status' => 200, 'body' => json_encode([
            'responses' => [[
                'fullTextAnnotation' => [
                    'text' => 'A',
                    'pages' => [[
                        'width' => 1000,
                        'blocks' => [[
                            'paragraphs' => [[
                                'boundingBox' => ['vertices' => [['y' => 0], ['x' => 200, 'y' => 0], ['x' => 200, 'y' => 100], ['y' => 100]]],
                                'words' => [['symbols' => [['text' => 'A', 'property' => []]]]],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ])]);

        $document = $service->recognizeDocument('fake-image-bytes');

        $this->assertSame(0.0, $document['paragraphs'][0]['xMin']);
        $this->assertSame(200.0, $document['paragraphs'][0]['xMax']);
    }

    public function testSurfacesAClearErrorWhenVisionReturnsAnErrorObject(): void
    {
        $service = $this->service(fn () => ['status' => 200, 'body' => json_encode([
            'responses' => [['error' => ['code' => 3, 'message' => 'Bad image data.']]],
        ])]);

        try {
            $service->recognizeDocument('fake-image-bytes');
            $this->fail('Expected a recipe.ocr_unavailable ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('recipe.ocr_unavailable', $e->getErrorCode());
        }
    }
}
