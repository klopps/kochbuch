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

- name: the recipe title, or null if there is none. If the title is written entirely in capital letters (common on printed cards and websites), write it in normal capitalization instead - in German: nouns capitalized, everything else lowercase except the first word (e.g. "SCHNELLER APFELKUCHEN MIT STREUSELN" -> "Schneller Apfelkuchen mit Streuseln").
- ingredients: one entry per ingredient line in reading order, split into amount (number, decimal point, fractions converted exactly, e.g. 1/2 -> 0.5, 1/3 -> 0.333333, 2/3 -> 0.666667; null if none), unit (e.g. g, kg, ml, l, EL, TL, Prise, Stück; null if none), name (the ingredient itself, WITHOUT the amount and unit - e.g. "1/3 Gurke" becomes amount 0.33, name "Gurke"; the number or fraction must not stay in the name) and note (extra remark like "fein gehackt"; null if none). A sub-heading inside the ingredient list (e.g. "Für den Teig") becomes an entry with is_heading true and only a name.
- steps: the preparation steps in order, each as one complete instruction; do not split a step at every line break of the handwriting. Sub-headings get is_heading true.
- notes: anything else on the page that belongs to the recipe (serving size, times, tips), or null.
- raw_text: the full text you read, line by line.

Keep the original language. Never invent content that is not on the image. Ignore the printed form labels of a template (e.g. "Zutaten für ___ Personen").
TXT;

    // A model whose quota frees up within this time counts as "per-minute
    // limited" for the user-facing message; anything later as "daily".
    private const SHORT_LIMIT_SECONDS = 3600;

    private readonly Closure $sender;
    private readonly Closure $clock;

    /**
     * @param (callable(string $url, array $payload, string $apiKey): array{status:int, body:string})|null $sender
     *        Defaults to a real curl-based POST; tests inject a fake that
     *        returns a canned {status, body} pair without any network call.
     * @param (callable(): int)|null $clock current Unix time (tests pin it)
     */
    public function __construct(
        private readonly string $apiKey,
        // Comma-separated, tried in order: Gemini models are retired
        // ("no longer available", HTTP 404) and individual ones are often
        // overloaded (503) for minutes - the next model in the list takes
        // over instead of failing the import.
        private readonly string $model = self::DEFAULT_MODELS,
        ?callable $sender = null,
        // Remembers rate-limited models across requests (null = don't).
        private readonly ?GeminiQuotaState $quotaState = null,
        ?callable $clock = null,
    ) {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * @return string[] the configured model list, in order
     */
    public static function modelList(string $models): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $models)), static fn (string $m) => $m !== ''));
    }

    /**
     * @return array{name: ?string, ingredients: array<int, array{name: string, amount: ?float, unit: ?string, note: ?string, is_heading: bool}>, steps: array<int, array{instruction: string, is_heading: bool}>, notes: ?string, raw_text: string}
     */
    public function extract(string $imageBytes, string $mime): array
    {
        return $this->extractMany([['bytes' => $imageBytes, 'mime' => $mime]]);
    }

    /**
     * Several photos of ONE recipe (e.g. six pages of a recipe card) in a
     * single request: one Gemini call instead of one per photo - far less
     * waiting (six sequential calls overran PHP's 30 s execution limit,
     * live) and only one request from the rate-limited quota - and the
     * model sees the whole recipe at once, so a step that continues on the
     * next photo stays one step.
     *
     * @param array<int, array{bytes: string, mime: string}> $images in page order
     * @return array{name: ?string, ingredients: array, steps: array, notes: ?string, raw_text: string}
     */
    public function extractMany(array $images): array
    {
        $prompt = self::PROMPT;
        if (count($images) > 1) {
            $prompt .= "\n\nThe " . count($images) . ' images are consecutive photos/pages of ONE recipe, in order. Combine them into a single recipe:'
                . ' ingredients and steps in the order they appear across the images, a step continuing on the next image stays one step, nothing repeated.';
        }
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => base64_encode($image['bytes'])]];
        }

        return $this->generate($parts);
    }

    /**
     * Same as extract(), for a recipe that already exists as plain text -
     * e.g. an Instagram caption shared into the app (see the PWA share
     * target in site.webmanifest/sw.js).
     *
     * @return array{name: ?string, ingredients: array, steps: array, notes: ?string, raw_text: string}
     */
    public function extractText(string $text, string $source = 'text shared from another app'): array
    {
        return $this->generate([
            ['text' => self::PROMPT . "\n\nThe recipe is given as text instead of an image - " . $source
                . ". It may contain unrelated content (navigation, ads, comments, hashtags, emojis, social-media chatter) - ignore all of it"
                . " and extract only the recipe. If there is no recipe, return empty ingredients and steps.\n\n" . $text],
        ]);
    }

    private function generate(array $parts): array
    {
        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::responseSchema(),
            ],
        ];

        $now = ($this->clock)();
        $models = self::modelList($this->model);
        $result = null;
        // model => {kind, until} for every model that is rate-limited -
        // remembered from an earlier request, or answered 429 just now.
        $limited = [];
        foreach ($models as $model) {
            $known = $this->quotaState?->exhaustedUntil($model, $now);
            if ($known !== null) {
                $limited[$model] = $known;
                $result = null;
                continue;
            }

            try {
                $result = ($this->sender)(self::API_BASE . rawurlencode($model) . ':generateContent', $payload, $this->apiKey);
            } catch (Throwable $e) {
                error_log('Kochbuch: Gemini request (' . $model . ') failed: ' . $e->getMessage());
                $result = null;
                continue;
            }
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $this->quotaState?->recordSuccess($model, $now);
                break;
            }
            if ($result['status'] === 429) {
                $limit = self::parseRateLimit($result['body'], $now);
                $limited[$model] = $limit;
                $this->quotaState?->recordRateLimit($model, $limit['kind'], $limit['until'], $now);
                error_log('Kochbuch: Gemini model ' . $model . ' rate-limited (' . $limit['kind'] . ') until ' . gmdate('c', $limit['until']));
                continue;
            }
            error_log('Kochbuch: Gemini request (' . $model . ') returned HTTP ' . $result['status'] . ': ' . $result['body']);
            // A client error other than "model gone" (404) would fail
            // identically on every model - stop early.
            if ($result['status'] < 500 && $result['status'] !== 404) {
                break;
            }
        }

        if ($result === null || $result['status'] < 200 || $result['status'] >= 300) {
            if ($models !== [] && count($limited) === count($models)) {
                throw self::rateLimitedException($limited, $now);
            }
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
     * Reads a 429 RESOURCE_EXHAUSTED body: google.rpc.QuotaFailure names the
     * violated quota (quotaId contains "PerDay" / "PerMinute"), google.rpc.
     * RetryInfo carries a retryDelay like "37s". A daily quota frees up at
     * the next midnight Pacific time; anything else after retryDelay
     * (default 60 s).
     *
     * @return array{kind: string, until: int} kind: "day" | "minute"
     */
    public static function parseRateLimit(string $body, int $now): array
    {
        $decoded = json_decode($body, true);
        $details = is_array($decoded['error']['details'] ?? null) ? $decoded['error']['details'] : [];

        $kind = 'minute';
        $retrySeconds = null;
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $type = (string) ($detail['@type'] ?? '');
            if (str_ends_with($type, 'QuotaFailure')) {
                foreach (is_array($detail['violations'] ?? null) ? $detail['violations'] : [] as $violation) {
                    $quotaId = is_array($violation) ? (string) ($violation['quotaId'] ?? '') : '';
                    if (stripos($quotaId, 'PerDay') !== false) {
                        $kind = 'day';
                    }
                }
            } elseif (str_ends_with($type, 'RetryInfo') && preg_match('/^(\d+(?:\.\d+)?)s$/', (string) ($detail['retryDelay'] ?? ''), $m) === 1) {
                $retrySeconds = (int) ceil((float) $m[1]);
            }
        }

        if ($kind === 'day') {
            return ['kind' => 'day', 'until' => GeminiQuotaState::nextDailyReset($now)];
        }

        return ['kind' => 'minute', 'until' => $now + max(1, $retrySeconds ?? 60)];
    }

    /**
     * All models are rate-limited: tell the user when it works again (the
     * earliest model to free up) instead of a generic "unavailable".
     *
     * @param array<string, array{kind: string, until: int}> $limited
     */
    private static function rateLimitedException(array $limited, int $now): ApiException
    {
        $until = min(array_column($limited, 'until'));
        $seconds = max(1, $until - $now);
        $daily = $seconds > self::SHORT_LIMIT_SECONDS;

        return new ApiException(
            $daily ? 'The daily image recognition quota is used up.' : 'Image recognition is busy (rate limit), please retry shortly.',
            429,
            $daily ? 'recipe.ocr_rate_limited_day' : 'recipe.ocr_rate_limited_minute',
            ['retry_at' => gmdate('c', $until), 'retry_in_seconds' => $seconds],
        );
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
            if ($amount !== null) {
                $name = self::stripLeadingAmount($name, $amount) ?? $name;
                $amount = self::snapToFraction($amount);
            }
            $ingredients[] = [
                'name' => $name,
                'amount' => $amount,
                'unit' => $isHeading ? null : UnitNormalizer::normalize(self::str($row['unit'] ?? null)),
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
            // Safety net if the model kept an all-caps title anyway.
            'name' => RecipeNameCase::fix(self::str($data['name'] ?? null)),
            'ingredients' => $ingredients,
            'steps' => $steps,
            'notes' => self::str($data['notes'] ?? null),
            'raw_text' => (string) self::str($data['raw_text'] ?? null),
        ];
    }

    /**
     * Models tend to round fractions ("1/3" -> 0.33). Snaps an amount that
     * lies within rounding distance of a common cooking fraction (halves,
     * thirds, quarters, sixths, eighths) onto that fraction's value at the
     * database's 6-decimal precision, so 3 x 1/3 scales back to exactly 1.
     */
    private static function snapToFraction(float $amount): float
    {
        foreach ([2, 3, 4, 6, 8] as $denominator) {
            $numerator = round($amount * $denominator);
            if ($numerator > 0 && abs($amount - $numerator / $denominator) <= 0.006) {
                return round($numerator / $denominator, 6);
            }
        }

        return $amount;
    }

    /**
     * The model sometimes fills `amount` (e.g. 0.33) and still leaves the
     * written quantity at the front of `name` ("1/3 Gurke" -> "0.33 1/3
     * Gurke" in the form). Strips a leading number/fraction from the name -
     * but only when its value matches `amount`, so a name that merely starts
     * with a digit ("7-Kräuter-Mix") stays untouched. Returns null when
     * nothing was stripped (or nothing would be left of the name).
     */
    private static function stripLeadingAmount(string $name, float $amount): ?string
    {
        if (preg_match('/^(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?)(?![\d\/.,])\s*(.*)$/su', $name, $m) !== 1 || trim($m[2]) === '') {
            return null;
        }
        $token = $m[1];
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $token, $p) === 1) {
            $value = (float) $p[1] + (float) $p[2] / max(1.0, (float) $p[3]);
        } elseif (preg_match('/^(\d+)\/(\d+)$/', $token, $p) === 1) {
            $value = (float) $p[1] / max(1.0, (float) $p[2]);
        } else {
            $value = (float) str_replace(',', '.', $token);
        }

        return abs($value - $amount) <= max(0.01, $amount * 0.02) ? trim($m[2]) : null;
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
