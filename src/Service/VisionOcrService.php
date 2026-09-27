<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Closure;
use RuntimeException;
use Throwable;
use Kochbuch\Exception\ApiException;

/**
 * Wraps Google Cloud Vision's `images:annotate` REST API (todo.md
 * "Importing Photos of Handwritten Recipes") - a single call per image, key
 * auth only, no OAuth/service-account JSON needed:
 *
 *   POST https://vision.googleapis.com/v1/images:annotate?key={apiKey}
 *   {requests: [{image: {content: <base64>}, features: [{type: "DOCUMENT_TEXT_DETECTION"}]}]}
 *   -> {responses: [{fullTextAnnotation: {text: "...", pages: [{width, blocks: [...]}]}}]}
 *
 * `DOCUMENT_TEXT_DETECTION` (rather than plain `TEXT_DETECTION`) is Vision's
 * dense-text/handwriting-oriented mode.
 *
 * Real handwritten recipe cards turned out to be two printed columns
 * ("Zutaten" / "Zubereitung" side by side) - the flat `fullTextAnnotation.text`
 * reading order interleaves both columns' lines unpredictably, which broke
 * RecipeOcrParser's plain line-based zone detection (verified against real
 * photos: ingredients were skipped entirely, and instructions came out as
 * dozens of one-line-per-OCR-line fragments). recognizeDocument() also
 * extracts each detected paragraph's bounding box so the parser can group
 * text by column geometry instead of raw reading order.
 *
 * The HTTP transport is injectable so tests never hit the real, external,
 * key-authenticated Vision API - same reasoning as BringService.
 */
final class VisionOcrService
{
    private const API_URL = 'https://vision.googleapis.com/v1/images:annotate';

    private readonly Closure $sender;

    /**
     * @param (callable(string $apiUrl, array $payload): array{status:int, body:string})|null $sender
     *        Defaults to a real curl-based POST; tests inject a fake that
     *        returns a canned {status, body} pair without any network call.
     */
    public function __construct(
        private readonly string $apiKey = '',
        ?callable $sender = null,
    ) {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
    }

    /**
     * @return array{text: string, paragraphs: array<int, array{text: string, xMin: float, xMax: float, yTop: float}>, pageWidth: float}
     */
    public function recognizeDocument(string $imageBytes): array
    {
        $payload = [
            'requests' => [
                [
                    'image' => ['content' => base64_encode($imageBytes)],
                    'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                ],
            ],
        ];

        try {
            $result = ($this->sender)(self::API_URL . '?key=' . urlencode($this->apiKey), $payload);
        } catch (Throwable $e) {
            // Logged rather than silently swallowed (mirrors BringService's
            // own convention) - the underlying cause (missing curl
            // extension, SSL/CA issues, an outbound firewall blocking
            // vision.googleapis.com, ...) is exactly what's needed to
            // diagnose a failure that only reproduces on one particular
            // server, and is otherwise invisible behind the generic
            // message below.
            error_log('Kochbuch: Vision OCR request failed: ' . $e->getMessage());
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            error_log('Kochbuch: Vision OCR request returned HTTP ' . $result['status'] . ': ' . $result['body']);
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        $decoded = json_decode($result['body'], true);
        $response = $decoded['responses'][0] ?? null;
        if (!is_array($response)) {
            error_log('Kochbuch: Vision OCR response had no usable "responses[0]": ' . $result['body']);
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }
        if (isset($response['error'])) {
            error_log('Kochbuch: Vision OCR returned an error object: ' . $result['body']);
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        $text = $response['fullTextAnnotation']['text'] ?? '';
        $page = $response['fullTextAnnotation']['pages'][0] ?? null;

        return [
            'text' => is_string($text) ? $text : '',
            'paragraphs' => is_array($page) ? self::extractParagraphs($page) : [],
            'pageWidth' => is_array($page) && is_numeric($page['width'] ?? null) ? (float) $page['width'] : 0.0,
        ];
    }

    /**
     * Reconstructs each paragraph's text (honoring Vision's own line/space
     * breaks between symbols, since a paragraph can itself span several
     * printed lines - e.g. a multi-line handwritten instruction) alongside
     * its bounding box, used by RecipeOcrParser to group paragraphs into
     * left/right columns by their horizontal position on the page.
     *
     * @return array<int, array{text: string, xMin: float, xMax: float, yTop: float}>
     */
    private static function extractParagraphs(array $page): array
    {
        $paragraphs = [];
        foreach ($page['blocks'] ?? [] as $block) {
            foreach ($block['paragraphs'] ?? [] as $paragraph) {
                $text = '';
                foreach ($paragraph['words'] ?? [] as $word) {
                    foreach ($word['symbols'] ?? [] as $symbol) {
                        $text .= (string) ($symbol['text'] ?? '');
                        $breakType = $symbol['property']['detectedBreak']['type'] ?? null;
                        if (in_array($breakType, ['SPACE', 'SURE_SPACE'], true)) {
                            $text .= ' ';
                        } elseif (in_array($breakType, ['EOL_SURE_SPACE', 'LINE_BREAK'], true)) {
                            $text .= "\n";
                        }
                    }
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }

                $vertices = $paragraph['boundingBox']['vertices'] ?? [];
                if ($vertices === []) {
                    continue;
                }
                // Vision omits a vertex's "x"/"y" key entirely when its
                // value is 0, rather than sending an explicit 0.
                $xs = array_map(static fn (array $v) => (float) ($v['x'] ?? 0), $vertices);
                $ys = array_map(static fn (array $v) => (float) ($v['y'] ?? 0), $vertices);

                $paragraphs[] = ['text' => $text, 'xMin' => (float) min($xs), 'xMax' => (float) max($xs), 'yTop' => (float) min($ys)];
            }
        }

        return $paragraphs;
    }

    /**
     * @return array{status:int, body:string}
     */
    private static function curlSender(string $apiUrl, array $payload): array
    {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
