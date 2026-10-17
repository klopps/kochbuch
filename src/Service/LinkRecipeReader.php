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
 *
 * Sites behind bot protection (todo.md "Rezepte von URL importieren versagt
 * bei REWE": rewe.de answers 403 + captcha - it recognizes the TLS
 * handshake of PHP's curl/OpenSSL, no request header changes that) are
 * retried once through a reader service (r.jina.ai, renders the page and
 * returns its HTML, incl. the JSON-LD; free without an account, ~20
 * requests/minute). Only then - and only the page URL goes there. If that
 * fails too, the original error stands; its message tells the user to
 * paste the recipe text instead.
 */
final class LinkRecipeReader
{
    private const MAX_TEXT_CHARS = 20000;
    // Below this, the "page text" is a login wall or cookie banner, not a recipe.
    private const MIN_TEXT_CHARS = 80;

    public const READER_SERVICE = 'https://r.jina.ai/';
    private const READER_TIMEOUT = 45;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher = new SafeUrlFetcher(),
        private readonly ?GeminiRecipeExtractor $gemini = null,
        private readonly SchemaOrgRecipeParser $schemaOrgParser = new SchemaOrgRecipeParser(),
        private readonly ?string $readerService = self::READER_SERVICE,
    ) {
    }

    /**
     * @return array{image: array{bytes: string, mime: string}}|array{draft: array{name: ?string, ingredients: array, steps: array, notes: ?string, raw_text: string, extra: array<string, mixed>}}
     */
    public function read(string $url): array
    {
        $viaReader = false;
        $page = $this->fetchPage($url, $viaReader);
        if (SafeUrlFetcher::isImage($page)) {
            return ['image' => ['bytes' => $page['body'], 'mime' => $page['mime']]];
        }
        if (!SafeUrlFetcher::isHtml($page)) {
            throw new ValidationException('No recipe was found at the link.', 'recipe.ocr_url_no_recipe');
        }
        $html = self::toUtf8($page['body'], $page['contentType']);

        $found = $this->recipeFromHtml($html, $page['url']);
        if ($found !== null) {
            return $found;
        }

        // Bot protection doesn't always answer with an error status - a
        // captcha/challenge page can come back as 200 (seen live for
        // rewe.de from the server's data-center IP). So a page without any
        // recipe gets one more try through the reader service before the
        // preview image (which on such a page would only be the shop logo).
        if (!$viaReader) {
            $proxied = $this->fetchViaReader($url);
            if ($proxied !== null) {
                $html = $proxied['body'];
                $found = $this->recipeFromHtml($html, $proxied['url']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $this->previewImageOrFail($html, $page['url']);
    }

    /**
     * A page the user's own device downloaded (Android app, CapacitorHttp -
     * see recipe-import-photo.js): sites whose bot protection blocks every
     * data-center IP (rewe.de blocks this server and the reader service)
     * still answer a phone. Same reading as for a fetched page; the HTML is
     * user-supplied input like pasted text, nothing is fetched for it except
     * a preview image.
     *
     * @return array{image: array{bytes: string, mime: string}}|array{draft: array}
     */
    public function readHtml(string $html, string $url): array
    {
        $html = self::toUtf8($html, 'text/html; charset=utf-8');
        $found = $this->recipeFromHtml($html, $url);
        if ($found !== null) {
            return $found;
        }

        return $this->previewImageOrFail($html, $url);
    }

    /**
     * Last resort for a page without a readable recipe: its preview image
     * (for the photo recognition), else "no recipe".
     *
     * @return array{image: array{bytes: string, mime: string}}
     */
    private function previewImageOrFail(string $html, string $pageUrl): array
    {
        // A bot-protection challenge (rewe.de: captcha page, even with
        // status 200) - its preview image would just be the shop's logo.
        if (self::looksLikeChallenge($html)) {
            throw new ValidationException('The site refuses automated access.', 'recipe.ocr_url_blocked');
        }

        $preview = SafeUrlFetcher::previewImageUrl($html, $pageUrl);
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

        if ($this->gemini === null && self::visibleText($html) !== '') {
            // Reading page text needs the Gemini reader.
            throw new ApiException('OCR is currently unavailable.', 502, 'recipe.ocr_unavailable');
        }

        throw new ValidationException('No recipe was found at the link.', 'recipe.ocr_url_no_recipe');
    }

    /**
     * Structured schema.org data first (no AI), else the page text via
     * Gemini - null when neither yields a recipe.
     *
     * @return array{draft: array}|null
     */
    private function recipeFromHtml(string $html, string $pageUrl): ?array
    {
        $structured = JsonLdRecipeFinder::find($html, $pageUrl);
        if ($structured !== null) {
            try {
                return ['draft' => $this->draftFromSchemaOrg($this->schemaOrgParser->parse($structured), $pageUrl)];
            } catch (InvalidArgumentException) {
                // A Recipe block without a name - fall through to the text.
            }
        }

        $text = self::visibleText($html);
        if ($this->gemini !== null && mb_strlen($text) >= self::MIN_TEXT_CHARS && !self::looksLikeChallenge($html)) {
            $draft = $this->gemini->extractText(mb_substr($text, 0, self::MAX_TEXT_CHARS), 'the text of the web page ' . $pageUrl);
            if ($draft['ingredients'] !== [] || $draft['steps'] !== []) {
                return ['draft' => [
                    'name' => $draft['name'],
                    'ingredients' => $draft['ingredients'],
                    'steps' => $draft['steps'],
                    'notes' => $draft['notes'],
                    'raw_text' => $draft['raw_text'] !== '' ? $draft['raw_text'] : $text,
                    'extra' => ['source_url' => $pageUrl],
                ]];
            }
        }

        return null;
    }

    /**
     * The page directly; if that is refused (blocked/unreachable), through
     * the reader service ($viaReader then true).
     *
     * @return array{body: string, mime: string, contentType: string, url: string}
     */
    private function fetchPage(string $url, bool &$viaReader): array
    {
        try {
            return $this->fetcher->fetch($url);
        } catch (ValidationException $e) {
            if (!in_array($e->getErrorCode(), ['recipe.ocr_url_blocked', 'recipe.ocr_url_unreachable'], true)) {
                throw $e;
            }
            $proxied = $this->fetchViaReader($url);
            if ($proxied === null) {
                throw $e;
            }
            $viaReader = true;

            return $proxied;
        }
    }

    /**
     * The page rendered by the reader service, as HTML (or an image), with
     * the page's own URL - null when the service is off or fails.
     *
     * @return array{body: string, mime: string, contentType: string, url: string}|null
     */
    private function fetchViaReader(string $url): ?array
    {
        if ($this->readerService === null) {
            return null;
        }
        try {
            $proxied = $this->fetcher->fetch($this->readerService . trim($url), ['X-Return-Format: html'], self::READER_TIMEOUT);
        } catch (ValidationException $e) {
            error_log('Kochbuch: reader service failed for ' . $url . ': ' . $e->getMessage());

            return null;
        }
        if (SafeUrlFetcher::isImage($proxied)) {
            return $proxied;
        }
        if (stripos($proxied['body'], '<html') === false && stripos($proxied['body'], '<!doctype') === false) {
            error_log('Kochbuch: reader service returned no HTML for ' . $url);

            return null;
        }

        // The reader answers as text/plain; the page is HTML, and its own
        // URL (not the reader's) is the base for relative links and the
        // recipe's source.
        return ['body' => $proxied['body'], 'mime' => 'text/html', 'contentType' => 'text/html; charset=utf-8', 'url' => trim($url)];
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

    /**
     * Checked on the visible text of short pages only: real recipe pages
     * carry "captcha"/"challenge-platform" in their scripts too (lecker.de,
     * rewe.de's Cloudflare include), a challenge page has little else.
     * rewe.de's: "Zeig uns, dass du ein Mensch bist. ... WAF Challenge Bot
     * protection".
     */
    private static function looksLikeChallenge(string $html): bool
    {
        $text = self::visibleText($html);

        return mb_strlen($text) < 1500
            && preg_match('/captcha|bot protection|waf challenge|ein Mensch bist|kein Roboter|not a robot|are you a robot|are you human|verify you are human/i', $text) === 1;
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
