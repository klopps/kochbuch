<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use InvalidArgumentException;
use Kochbuch\Exception\ApiException;
use Kochbuch\Exception\ValidationException;

/**
 * Turns a shared link into a recipe draft (todo.md "Share to Kochbuch").
 * Chrome on Android won't hand its own "share image" files to an installed
 * web app, so recipes get into Kochbuch from the browser as links - and for
 * a web page the page itself is a far better source than any picture of it:
 *
 *  1. Structured data: most recipe sites embed schema.org `Recipe` JSON-LD.
 *     Found by JsonLdRecipeFinder (shared with the Chefkoch import, incl.
 *     its @id resolution and the dropped Chefkoch SEO description) and
 *     mapped exactly by SchemaOrgRecipeParser - no AI needed, and it brings
 *     servings, times, calories, tags and the source along.
 *  2. Page text: the visible text of the page (main/article content when
 *     marked up, without scripts, navigation, header, footer) goes to
 *     Gemini, same as pasted recipe text.
 *  3. Only when neither yields a recipe: the page's preview image.
 *
 * A link straight to a picture is returned as an image for the normal
 * photo recognition. Fetching itself (and its SSRF protection) is
 * SafeUrlFetcher's job.
 */
final class LinkRecipeReader
{
    private const MAX_TEXT_CHARS = 20000;
    // Below this, the "page text" is a login wall or cookie banner, not a recipe.
    private const MIN_TEXT_CHARS = 80;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher = new SafeUrlFetcher(),
        private readonly ?GeminiRecipeExtractor $gemini = null,
        private readonly SchemaOrgRecipeParser $schemaOrgParser = new SchemaOrgRecipeParser(),
    ) {
    }

    /**
     * @return array{image: array{bytes: string, mime: string}}|array{draft: array{name: ?string, ingredients: array, steps: array, notes: ?string, raw_text: string, extra: array<string, mixed>}}
     */
    public function read(string $url): array
    {
        $page = $this->fetcher->fetch($url);
        if (SafeUrlFetcher::isImage($page)) {
            return ['image' => ['bytes' => $page['body'], 'mime' => $page['mime']]];
        }
        if (!SafeUrlFetcher::isHtml($page)) {
            throw new ValidationException('No recipe was found at the link.', 'recipe.ocr_url_no_recipe');
        }
        $html = self::toUtf8($page['body'], $page['contentType']);

        $structured = JsonLdRecipeFinder::find($html, $page['url']);
        if ($structured !== null) {
            try {
                return ['draft' => $this->draftFromSchemaOrg($this->schemaOrgParser->parse($structured), $page['url'])];
            } catch (InvalidArgumentException) {
                // A Recipe block without a name - fall through to the text.
            }
        }

        $text = self::visibleText($html);
        if ($this->gemini !== null && mb_strlen($text) >= self::MIN_TEXT_CHARS) {
            $draft = $this->gemini->extractText(mb_substr($text, 0, self::MAX_TEXT_CHARS), 'the text of the web page ' . $page['url']);
            if ($draft['ingredients'] !== [] || $draft['steps'] !== []) {
                return ['draft' => [
                    'name' => $draft['name'],
                    'ingredients' => $draft['ingredients'],
                    'steps' => $draft['steps'],
                    'notes' => $draft['notes'],
                    'raw_text' => $draft['raw_text'] !== '' ? $draft['raw_text'] : $text,
                    'extra' => ['source_url' => $page['url']],
                ]];
            }
        }

        $preview = SafeUrlFetcher::previewImageUrl($html, $page['url']);
        if ($preview !== null) {
            try {
                $image = $this->fetcher->fetch($preview);
                if (SafeUrlFetcher::isImage($image)) {
                    return ['image' => ['bytes' => $image['body'], 'mime' => $image['mime']]];
                }
            } catch (ValidationException) {
                // Unusable preview image - report "no recipe" below.
            }
        }

        if ($this->gemini === null && $text !== '') {
            // Reading page text needs the Gemini reader.
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        throw new ValidationException('No recipe was found at the link.', 'recipe.ocr_url_no_recipe');
    }

    private function draftFromSchemaOrg(array $mapped, string $pageUrl): array
    {
        $lines = [$mapped['name']];
        foreach ($mapped['ingredients'] as $ingredient) {
            $lines[] = trim(implode(' ', array_filter([
                $ingredient['amount'] !== null ? rtrim(rtrim(number_format((float) $ingredient['amount'], 3, '.', ''), '0'), '.') : null,
                $ingredient['unit'] ?? null,
                $ingredient['name'],
            ], static fn ($v) => $v !== null && $v !== '')));
        }
        foreach ($mapped['steps'] as $i => $step) {
            $lines[] = ($i + 1) . '. ' . $step['instruction'];
        }

        $extra = array_filter([
            'description' => $mapped['description'],
            'servings' => $mapped['servings'],
            'prep_time_minutes' => $mapped['prep_time_minutes'],
            'cook_time_minutes' => $mapped['cook_time_minutes'],
            'rest_time_minutes' => $mapped['rest_time_minutes'],
            'calories' => $mapped['calories'],
            'source' => $mapped['source'],
            'source_url' => $mapped['source_url'] ?? $pageUrl,
            'tags' => $mapped['tags'],
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        $extra += ['source_url' => $pageUrl];
        foreach (['is_vegan', 'is_vegetarian', 'is_pescetarian'] as $flag) {
            if ($mapped[$flag]) {
                $extra[$flag] = true;
            }
        }

        return [
            'name' => $mapped['name'],
            'ingredients' => $mapped['ingredients'],
            'steps' => $mapped['steps'],
            'notes' => null,
            'raw_text' => implode("\n", $lines),
            'extra' => $extra,
        ];
    }

    /**
     * Readable text of the page: <main>/<article> when present (less menu
     * and footer noise), without scripts, styles and navigation, block
     * elements turned into line breaks.
     */
    public static function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|template|iframe|head)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;

        foreach (['main', 'article'] as $container) {
            if (preg_match_all('#<' . $container . '\b[^>]*>(.*?)</' . $container . '>#is', $html, $m) > 0) {
                $longest = array_reduce($m[1], static fn (string $carry, string $part) => strlen($part) > strlen($carry) ? $part : $carry, '');
                if (strlen(strip_tags($longest)) > 200) {
                    $html = $longest;
                    break;
                }
            }
        }

        $html = preg_replace('#<(nav|header|footer|aside|form|button|select)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|section|article|ul|ol|table|dd|dt|blockquote)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $lines = array_filter(array_map('trim', explode("\n", $text)), static fn (string $l) => $l !== '');

        return implode("\n", $lines);
    }

    private static function toUtf8(string $body, string $contentType): string
    {
        if (preg_match('/charset=([\w-]+)/i', $contentType, $m) === 1 && strcasecmp($m[1], 'utf-8') !== 0) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $m[1]);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : (mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1') ?: $body);
    }
}
