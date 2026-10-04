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
            SafeUrlFetcherTest::fetcher($this, ['https://social.example.com/p/2' => $this->page('<html><body>Melde dich an, um diesen Beitrag zu sehen.</body></html>')]),
            $this->gemini($prompts, []),
        );

        try {
            $reader->read('https://social.example.com/p/2');
            $this->fail('Expected a recipe.ocr_url_no_recipe ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('recipe.ocr_url_no_recipe', $e->getErrorCode());
        }
    }
}
