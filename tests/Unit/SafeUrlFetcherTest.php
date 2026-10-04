<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\SafeUrlFetcher;
use PHPUnit\Framework\TestCase;

/**
 * SafeUrlFetcher with an injected fake transport and DNS resolver - no
 * network access. Focuses on what makes fetching a user-supplied URL safe
 * (only public hosts, every redirect re-checked, size limit) plus the
 * Google image-search unwrapping and preview-image lookup.
 */
final class SafeUrlFetcherTest extends TestCase
{
    public const PUBLIC_IP = '93.184.216.34';

    public static function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public static function response(int $status, string $body = '', ?string $location = null, string $contentType = '', bool $truncated = false): array
    {
        return ['status' => $status, 'contentType' => $contentType, 'location' => $location, 'body' => $body, 'truncated' => $truncated];
    }

    /**
     * @param array<string, array> $responses url => response
     * @param array<string, string[]> $dns host => ips (default: a public IP)
     */
    public static function fetcher(TestCase $test, array $responses, array $dns = [], ?array &$requested = null): SafeUrlFetcher
    {
        $requested = [];

        return new SafeUrlFetcher(
            function (string $url, string $ip) use ($test, $responses, &$requested): array {
                $requested[] = [$url, $ip];
                if (!isset($responses[$url])) {
                    $test->fail('Unexpected request to ' . $url);
                }

                return $responses[$url];
            },
            fn (string $host): array => $dns[$host] ?? [self::PUBLIC_IP],
        );
    }

    private function assertRejected(string $expectedCode, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a ' . $expectedCode . ' ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame($expectedCode, $e->getErrorCode());
        }
    }

    public function testFetchesAndSniffsTheRealTypeAndPinsTheCheckedIp(): void
    {
        $fetcher = self::fetcher($this, ['https://img.example.com/a.jpg' => self::response(200, self::png(), null, 'image/jpeg')], [], $requested);

        $page = $fetcher->fetch('https://img.example.com/a.jpg');

        $this->assertSame('image/png', $page['mime']);
        $this->assertTrue(SafeUrlFetcher::isImage($page));
        $this->assertSame([['https://img.example.com/a.jpg', self::PUBLIC_IP]], $requested);
    }

    public function testUnwrapsAGoogleImageSearchLink(): void
    {
        $fetcher = self::fetcher($this, ['https://cdn.example.com/b.png' => self::response(200, self::png())]);

        $page = $fetcher->fetch('https://www.google.com/imgres?imgurl=' . rawurlencode('https://cdn.example.com/b.png') . '&imgrefurl=https%3A%2F%2Fexample.com');

        $this->assertSame('https://cdn.example.com/b.png', $page['url']);
    }

    public function testFollowsARedirect(): void
    {
        $fetcher = self::fetcher($this, [
            'https://short.example.com/x' => self::response(302, '', '/rezept/1'),
            'https://short.example.com/rezept/1' => self::response(200, '<html></html>', null, 'text/html'),
        ]);

        $page = $fetcher->fetch('https://short.example.com/x');

        $this->assertSame('https://short.example.com/rezept/1', $page['url']);
        $this->assertTrue(SafeUrlFetcher::isHtml($page));
    }

    public function testRejectsHostsResolvingToPrivateAddressesWithoutConnecting(): void
    {
        $fetcher = self::fetcher($this, [], ['intranet.example.com' => ['10.0.0.5']], $requested);

        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('https://intranet.example.com/a.png'));
        $this->assertSame([], $requested);
    }

    public function testRejectsLoopbackLiteralsOtherSchemesPortsAndCredentials(): void
    {
        $fetcher = self::fetcher($this, []);

        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('http://127.0.0.1/a.png'));
        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('http://[::1]/a.png'));
        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('file:///etc/passwd'));
        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('https://img.example.com:8080/a.png'));
        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('https://user:pw@img.example.com/a.png'));
        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('nur text'));
    }

    public function testRejectsARedirectIntoThePrivateNetwork(): void
    {
        $fetcher = self::fetcher($this, ['https://evil.example.com/x' => self::response(302, '', 'http://169.254.169.254/latest/meta-data')]);

        $this->assertRejected('recipe.ocr_url_invalid', fn () => $fetcher->fetch('https://evil.example.com/x'));
    }

    public function testReportsOversizedDownloadsAndHttpErrors(): void
    {
        $fetcher = self::fetcher($this, [
            'https://img.example.com/huge.jpg' => self::response(200, 'partial', null, 'image/jpeg', true),
            'https://img.example.com/missing.jpg' => self::response(404),
        ]);

        $this->assertRejected('recipe.image_too_large', fn () => $fetcher->fetch('https://img.example.com/huge.jpg'));
        $this->assertRejected('recipe.ocr_url_fetch_failed', fn () => $fetcher->fetch('https://img.example.com/missing.jpg'));
    }

    public function testFindsThePreviewImageInEitherAttributeOrder(): void
    {
        $html = '<head><meta name="twitter:image" content="https://x.example/t.jpg"><meta content="/img/og.jpg" property="og:image"></head>';

        $this->assertSame('https://www.example.com/img/og.jpg', SafeUrlFetcher::previewImageUrl($html, 'https://www.example.com/rezepte/a'));
        $this->assertNull(SafeUrlFetcher::previewImageUrl('<head><title>x</title></head>', 'https://www.example.com/'));
    }
}
