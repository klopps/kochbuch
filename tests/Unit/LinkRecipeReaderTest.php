<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\GeminiRecipeExtractor;
use Kochbuch\Service\LinkRecipeReader;
use PHPUnit\Framework\TestCase;

/**
 * LinkRecipeReader's order of preference for a shared link: structured
 * schema.org data (no AI), then the page text (Gemini), then the preview
 * image - and a direct picture link as an image. Fake transport and fake
 * Gemini, no network.
 */
final class LinkRecipeReaderTest extends TestCase
{
    private function gemini(?array &$prompts, array $recipe): GeminiRecipeExtractor
    {
        $prompts = [];

        return new GeminiRecipeExtractor('k', 'm', function (string $url, array $payload) use (&$prompts, $recipe) {
            $prompts[] = $payload['contents'][0]['parts'][0]['text'];

            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($recipe)]]]]]])];
        });
    }

    private function page(string $body): array
    {
        return SafeUrlFetcherTest::response(200, $body, null, 'text/html; charset=utf-8');
    }

    public function testReadsStructuredRecipeDataWithoutAskingTheAi(): void
    {
        $jsonLd = json_encode(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebPage', 'name' => 'Seite'],
            [
                '@type' => ['Recipe'],
                'name' => 'Linsensuppe',
                'recipeYield' => '4 Portionen',
                'prepTime' => 'PT15M',
                'cookTime' => 'PT40M',
                'recipeIngredient' => ['200 g Linsen', '1 Zwiebel'],
                'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Zwiebel anbraten.'], ['@type' => 'HowToStep', 'text' => 'Linsen kochen.']],
                'keywords' => 'Suppe, Hülsenfrüchte',
            ],
        ]]);
        $html = '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body><main>viel Text</main></body></html>';
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, ['https://www.example.com/linsensuppe' => $this->page($html)]),
            $this->gemini($prompts, []),
        );

        $draft = $reader->read('https://www.example.com/linsensuppe')['draft'];

        $this->assertSame([], $prompts);
        $this->assertSame('Linsensuppe', $draft['name']);
        $this->assertSame(['Linsen', 'Zwiebel'], array_column($draft['ingredients'], 'name'));
        $this->assertSame(200.0, (float) $draft['ingredients'][0]['amount']);
        $this->assertSame(['Zwiebel anbraten.', 'Linsen kochen.'], array_column($draft['steps'], 'instruction'));
        $this->assertSame(4, $draft['extra']['servings']);
        $this->assertSame(15, $draft['extra']['prep_time_minutes']);
        $this->assertSame(40, $draft['extra']['cook_time_minutes']);
        $this->assertSame('https://www.example.com/linsensuppe', $draft['extra']['source_url']);
        $this->assertStringContainsString('200 g Linsen', $draft['raw_text']);
    }

    public function testFallsBackToThePageTextReadByGemini(): void
    {
        $html = '<html><head><title>Blog</title><script>var x = 1;</script></head><body>'
            . '<nav>Home | Rezepte | Kontakt</nav>'
            . '<article><h1>Omas Kartoffelsalat</h1><p>Zutaten: 1 kg Kartoffeln, 1 Zwiebel, Essig, Öl.</p>'
            . '<p>Kartoffeln kochen, schälen, in Scheiben schneiden und mit der Marinade mischen. Eine Stunde ziehen lassen.</p></article>'
            . '<footer>Impressum</footer></body></html>';
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, ['https://blog.example.com/salat' => $this->page($html)]),
            $this->gemini($prompts, [
                'name' => 'Omas Kartoffelsalat',
                'ingredients' => [['name' => 'Kartoffeln', 'amount' => 1, 'unit' => 'kg', 'note' => null, 'is_heading' => false]],
                'steps' => [['instruction' => 'Kartoffeln kochen.', 'is_heading' => false]],
                'raw_text' => 'Omas Kartoffelsalat',
            ]),
        );

        $draft = $reader->read('https://blog.example.com/salat')['draft'];

        $this->assertSame('Omas Kartoffelsalat', $draft['name']);
        $this->assertSame('https://blog.example.com/salat', $draft['extra']['source_url']);
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('1 kg Kartoffeln', $prompts[0]);
        $this->assertStringContainsString('https://blog.example.com/salat', $prompts[0]);
        $this->assertStringNotContainsString('var x = 1', $prompts[0]);
        $this->assertStringNotContainsString('Kontakt', $prompts[0]);
    }

    public function testADirectPictureLinkIsReturnedAsAnImage(): void
    {
        $png = SafeUrlFetcherTest::png();
        $reader = new LinkRecipeReader(SafeUrlFetcherTest::fetcher($this, ['https://img.example.com/karte.png' => SafeUrlFetcherTest::response(200, $png)]));

        $result = $reader->read('https://img.example.com/karte.png');

        $this->assertSame('image/png', $result['image']['mime']);
        $this->assertSame($png, $result['image']['bytes']);
    }

    public function testUsesThePreviewImageOnlyWhenThePageHasNoRecipeText(): void
    {
        // A login wall: hardly any text, no structured data, but a preview image.
        $html = '<html><head><meta property="og:image" content="https://cdn.example.com/post.png"></head><body>Melde dich an.</body></html>';
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://social.example.com/p/1' => $this->page($html),
                // No recipe on the page -> one try through the reader service, same wall.
                'https://r.jina.ai/https://social.example.com/p/1' => SafeUrlFetcherTest::response(200, $html, null, 'text/plain'),
                'https://cdn.example.com/post.png' => SafeUrlFetcherTest::response(200, SafeUrlFetcherTest::png()),
            ]),
            $this->gemini($prompts, []),
        );

        $result = $reader->read('https://social.example.com/p/1');

        $this->assertArrayHasKey('image', $result);
        $this->assertSame([], $prompts, 'Too little text to be worth an AI call.');
    }

    public function testReportsWhenThereIsNoRecipeAtAll(): void
    {
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://social.example.com/p/2' => $this->page('<html><body>Melde dich an, um diesen Beitrag zu sehen.</body></html>'),
                'https://r.jina.ai/https://social.example.com/p/2' => SafeUrlFetcherTest::response(200, '<html><body>Melde dich an, um diesen Beitrag zu sehen.</body></html>', null, 'text/plain'),
            ]),
            $this->gemini($prompts, []),
        );

        try {
            $reader->read('https://social.example.com/p/2');
            $this->fail('Expected a recipe.ocr_url_no_recipe ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ocr_url_no_recipe', $e->getErrorCode());
        }
    }

    /**
     * todo.md "Rezepte von URL importieren versagt bei REWE": a site that
     * blocks the direct request (403) is read through the reader service,
     * whose text/plain answer is the page HTML - the recipe's source stays
     * the original URL.
     */
    public function testBlockedSiteIsReadThroughTheReaderService(): void
    {
        $jsonLd = json_encode(['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Rosenkohl-Pide', 'recipeIngredient' => ['300 g Rosenkohl'], 'recipeInstructions' => 'Backen.']);
        $html = '<!DOCTYPE html><html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body></body></html>';
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://www.rewe.de/rezepte/rosenkohl-pide/' => SafeUrlFetcherTest::response(403, '<html>captcha</html>', null, 'text/html'),
                'https://r.jina.ai/https://www.rewe.de/rezepte/rosenkohl-pide/' => SafeUrlFetcherTest::response(200, $html, null, 'text/plain; charset=utf-8'),
            ]),
            $this->gemini($prompts, []),
        );

        $draft = $reader->read('https://www.rewe.de/rezepte/rosenkohl-pide/')['draft'];

        $this->assertSame('Rosenkohl-Pide', $draft['name']);
        $this->assertSame('Rosenkohl', $draft['ingredients'][0]['name']);
        $this->assertSame('https://www.rewe.de/rezepte/rosenkohl-pide/', $draft['extra']['source_url']);
        $this->assertSame([], $prompts);
    }

    public function testBlockedSiteThatTheReaderCannotReadEitherReportsBlocked(): void
    {
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://shop.example.com/rezept' => SafeUrlFetcherTest::response(403, 'nope', null, 'text/html'),
                'https://r.jina.ai/https://shop.example.com/rezept' => SafeUrlFetcherTest::response(429, 'rate limited', null, 'text/plain'),
            ]),
            $this->gemini($prompts, []),
        );

        try {
            $reader->read('https://shop.example.com/rezept');
            $this->fail('Expected recipe.ocr_url_blocked');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ocr_url_blocked', $e->getErrorCode());
        }
    }

    public function testMissingPageIsNotRetriedThroughTheReaderService(): void
    {
        // The fake transport fails the test on any unexpected (reader) request.
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, ['https://blog.example.com/weg' => SafeUrlFetcherTest::response(404, 'gone', null, 'text/html')]),
            $this->gemini($prompts, []),
        );

        try {
            $reader->read('https://blog.example.com/weg');
            $this->fail('Expected recipe.ocr_url_fetch_failed');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ocr_url_fetch_failed', $e->getErrorCode());
        }
    }

    /**
     * Live, rewe.de answered the server's data-center IP with a 200 captcha
     * page (not 403) - a page without any recipe is retried through the
     * reader service too, before falling back to its preview image.
     */
    public function testChallengePageWithStatus200IsRetriedThroughTheReaderService(): void
    {
        $captcha = '<html><head><title>REWE Onlineshop</title><meta property="og:image" content="https://www.rewe.de/logo.png"></head><body>Bitte bestätige, dass du kein Roboter bist.</body></html>';
        $jsonLd = json_encode(['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Rosenkohl-Pide', 'recipeIngredient' => ['300 g Rosenkohl'], 'recipeInstructions' => 'Backen.']);
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://www.rewe.de/rezepte/rosenkohl-pide/' => $this->page($captcha),
                'https://r.jina.ai/https://www.rewe.de/rezepte/rosenkohl-pide/' => SafeUrlFetcherTest::response(200, '<!DOCTYPE html><html><head><script type="application/ld+json">' . $jsonLd . '</script></head></html>', null, 'text/plain'),
            ]),
            $this->gemini($prompts, []),
        );

        $draft = $reader->read('https://www.rewe.de/rezepte/rosenkohl-pide/')['draft'];

        $this->assertSame('Rosenkohl-Pide', $draft['name']);
        $this->assertSame('https://www.rewe.de/rezepte/rosenkohl-pide/', $draft['extra']['source_url']);
    }

    /**
     * When the server and the reader service only get the captcha page,
     * that is reported as blocked (not "no recipe", and not the shop logo
     * as preview image) - the Android app then loads the page itself.
     */
    public function testChallengePageEverywhereIsReportedAsBlocked(): void
    {
        $captcha = '<html><head><title>REWE Onlineshop</title><meta property="og:image" content="https://www.rewe.de/logo.png"></head>'
            . '<body><p>Zeig uns, dass du ein Mensch bist. Setz ein Häkchen im Kasten unter diesem Text.</p><p>Technische Hinweise WAF Challenge Bot protection</p></body></html>';
        $reader = new LinkRecipeReader(
            SafeUrlFetcherTest::fetcher($this, [
                'https://www.rewe.de/rezepte/x/' => $this->page($captcha),
                'https://r.jina.ai/https://www.rewe.de/rezepte/x/' => SafeUrlFetcherTest::response(200, $captcha, null, 'text/plain'),
            ]),
            $this->gemini($prompts, []),
        );

        try {
            $reader->read('https://www.rewe.de/rezepte/x/');
            $this->fail('Expected recipe.ocr_url_blocked');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ocr_url_blocked', $e->getErrorCode());
        }
        $this->assertSame([], $prompts, 'No AI call for a captcha page.');
    }

    /**
     * The page as downloaded by the Android app (RecipeController::ocr()
     * page_html) is read without fetching anything.
     */
    public function testReadsPageHtmlSuppliedByTheApp(): void
    {
        $jsonLd = json_encode(['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Rosenkohl-Pide', 'recipeIngredient' => ['300 g Rosenkohl'], 'recipeInstructions' => 'Backen.']);
        $html = '<html><head><script type="application/ld+json">' . $jsonLd . '</script><script src="/cdn-cgi/challenge-platform/x.js"></script></head><body>Rosenkohl-Pide</body></html>';
        $reader = new LinkRecipeReader(SafeUrlFetcherTest::fetcher($this, []), $this->gemini($prompts, []));

        $draft = $reader->readHtml($html, 'https://www.rewe.de/rezepte/rosenkohl-pide/')['draft'];

        $this->assertSame('Rosenkohl-Pide', $draft['name']);
        $this->assertSame('https://www.rewe.de/rezepte/rosenkohl-pide/', $draft['extra']['source_url']);
    }
}
