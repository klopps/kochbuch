<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Closure;
use RuntimeException;
use Throwable;
use Kochbuch\Exception\ApiException;

/**
 * Reads a recipe straight from a photo via Google's Gemini API (todo.md
 * "The recognition performance when importing from photos is very poor") -
 * layout-independent where VisionOcrService + RecipeOcrParser's heuristics
 * only cope with two printed columns under fixed "Zutaten"/"Zubereitung"
 * headings. The model sees the image itself, so one- or multi-column cards,
 * differently named headings, handwriting and printed pages all go through
 * the same call, and it answers in a fixed JSON schema (Gemini's
 * `responseSchema`) that is mapped onto the same draft shape
 * RecipeOcrParser::parse() returns.
 *
 *   POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *   x-goog-api-key: {apiKey}
 *
 * The HTTP transport is injectable so tests never hit the real,
 * key-authenticated API - same reasoning as VisionOcrService/BringService.
 */
final class GeminiRecipeExtractor
{
    public const DEFAULT_MODELS = 'gemini-3.1-flash-lite,gemini-3.6-flash,gemini-3-flash-preview';

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const PROMPT = <<<'TXT'
You read recipes from photos (handwritten or printed, one or several columns, any language, any headings). Extract the recipe on the image.

- name: the recipe title, or null if there is none.
- ingredients: one entry per ingredient line in reading order, split into amount (number, decimal point, fractions converted, e.g. 1/2 -> 0.5; null if none), unit (e.g. g, kg, ml, l, EL, TL, Prise, Stück; null if none), name (the ingredient itself) and note (extra remark like "fein gehackt"; null if none). A sub-heading inside the ingredient list (e.g. "Für den Teig") becomes an entry with is_heading true and only a name.
- steps: the preparation steps in order, each as one complete instruction; do not split a step at every line break of the handwriting. Sub-headings get is_heading true.
- notes: anything else on the page that belongs to the recipe (serving size, times, tips), or null.
- raw_text: the full text you read, line by line.

Keep the original language. Never invent content that is not on the image. Ignore the printed form labels of a template (e.g. "Zutaten für ___ Personen").
TXT;

    private readonly Closure $sender;

    /**
     * @param (callable(string $url, array $payload, string $apiKey): array{status:int, body:string})|null $sender
     *        Defaults to a real curl-based POST; tests inject a fake that
     *        returns a canned {status, body} pair without any network call.
     */
    public function __construct(
        private readonly string $apiKey,
        // Comma-separated, tried in order: Gemini models are retired
        // ("no longer available", HTTP 404) and individual ones are often
        // overloaded (503) for minutes - the next model in the list takes
        // over instead of failing the import.
        private readonly string $model = self::DEFAULT_MODELS,
        ?callable $sender = null,
    ) {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
    }

    /**
     * @return array{name: ?string, ingredients: array<int, array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}>, steps: array<int, array{instruction: string, is_heading: bool}>, notes: ?string, raw_text: string}
     */
    public function extract(string $imageBytes, string $mime): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => self::PROMPT],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($imageBytes)]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::responseSchema(),
            ],
        ];

        $result = null;
        foreach (array_filter(array_map('trim', explode(',', $this->model))) as $model) {
            try {
                $result = ($this->sender)(self::API_BASE . rawurlencode($model) . ':generateContent', $payload, $this->apiKey);
            } catch (Throwable $e) {
                error_log('Kochbuch: Gemini request (' . $model . ') failed: ' . $e->getMessage());
                $result = null;
                continue;
            }
            if ($result['status'] >= 200 && $result['status'] < 300) {
                break;
            }
            error_log('Kochbuch: Gemini request (' . $model . ') returned HTTP ' . $result['status'] . ': ' . $result['body']);
            // A client error other than "model gone" (404) / rate limit
            // (429) would fail identically on every model - stop early.
            if ($result['status'] < 500 && !in_array($result['status'], [404, 429], true)) {
                break;
            }
        }

        if ($result === null || $result['status'] < 200 || $result['status'] >= 300) {
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        $decoded = json_decode($result['body'], true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) {
            error_log('Kochbuch: Gemini response had no usable JSON recipe: ' . $result['body']);
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        return self::normalize($data);
    }

    /**
     * Never trusts the model's JSON shape: every field is type-checked and
     * trimmed, so a malformed answer degrades to an emptier draft instead of
     * breaking the create form (which still validates everything on save).
     */
    private static function normalize(array $data): array
    {
        $ingredients = [];
        foreach (is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::str($row['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $isHeading = ($row['is_heading'] ?? false) === true;
            $amount = !$isHeading && is_numeric($row['amount'] ?? null) && (float) $row['amount'] > 0 ? (float) $row['amount'] : null;
            $ingredients[] = [
                'name' => $name,
                'amount' => $amount,
                'unit' => $isHeading ? null : self::str($row['unit'] ?? null),
                'note' => $isHeading ? null : self::str($row['note'] ?? null),
                'is_heading' => $isHeading,
            ];
        }

        $steps = [];
        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $row) {
            $instruction = is_array($row) ? self::str($row['instruction'] ?? null) : null;
            if ($instruction === null) {
                continue;
            }
            $steps[] = ['instruction' => $instruction, 'is_heading' => ($row['is_heading'] ?? false) === true];
        }

        return [
            'name' => self::str($data['name'] ?? null),
            'ingredients' => $ingredients,
            'steps' => $steps,
            'notes' => self::str($data['notes'] ?? null),
            'raw_text' => (string) self::str($data['raw_text'] ?? null),
        ];
    }

    private static function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function responseSchema(): array
    {
        $nullableString = ['type' => 'STRING', 'nullable' => true];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'name' => $nullableString,
                'ingredients' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'amount' => ['type' => 'NUMBER', 'nullable' => true],
                            'unit' => $nullableString,
                            'note' => $nullableString,
                            'is_heading' => ['type' => 'BOOLEAN'],
                        ],
                        'required' => ['name', 'is_heading'],
                    ],
                ],
                'steps' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'instruction' => ['type' => 'STRING'],
                            'is_heading' => ['type' => 'BOOLEAN'],
                        ],
                        'required' => ['instruction', 'is_heading'],
                    ],
                ],
                'notes' => $nullableString,
                'raw_text' => ['type' => 'STRING'],
            ],
            'required' => ['ingredients', 'steps', 'raw_text'],
        ];
    }

    /**
     * @return array{status:int, body:string}
     */
    private static function curlSender(string $url, array $payload, string $apiKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
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
