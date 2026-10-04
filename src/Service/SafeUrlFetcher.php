<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Closure;
use RuntimeException;
use Throwable;
use Kochbuch\Exception\ValidationException;

/**
 * Downloads a user-supplied link for the recipe import (todo.md "Share to
 * Kochbuch", see LinkRecipeReader): a web page to read the recipe from, or
 * a picture to recognize. Google image-search result links
 * (`/imgres?imgurl=...`) are unwrapped to the real image.
 *
 * The URL comes from the user, so every request - including each redirect
 * hop - is checked against server-side request forgery: http/https only,
 * default ports only, no credentials in the URL, and the host must resolve
 * exclusively to public IP addresses. The checked IP is pinned for the
 * actual connection (CURLOPT_RESOLVE), so a DNS answer can't change between
 * check and use. Downloads stop at MAX_BYTES.
 *
 * Transport and DNS resolver are injectable so tests never touch the network
 * - same reasoning as VisionOcrService/BringService.
 */
final class SafeUrlFetcher
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_REDIRECTS = 4;

    private readonly Closure $sender;
    private readonly Closure $resolver;

    /**
     * @param (callable(string $url, string $ip, int $maxBytes): array{status:int, contentType:string, location:?string, body:string, truncated:bool})|null $sender
     * @param (callable(string $host): string[])|null $resolver
     */
    public function __construct(?callable $sender = null, ?callable $resolver = null)
    {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : self::dnsResolve(...);
    }

    /**
     * GET with manually followed redirects, each hop validated anew.
     * `mime` is sniffed from the bytes, not taken from the server's header.
     *
     * @return array{body: string, mime: string, contentType: string, url: string}
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $url = self::unwrapImageUrl($url);
            $ip = $this->validate($url);

            try {
                $result = ($this->sender)($url, $ip, self::MAX_BYTES);
            } catch (Throwable $e) {
                error_log('Kochbuch: fetching shared link failed: ' . $e->getMessage());
                throw new ValidationException('The link could not be loaded.', 'recipe.ocr_url_fetch_failed');
            }

            if (in_array($result['status'], [301, 302, 303, 307, 308], true) && ($result['location'] ?? '') !== '') {
                $url = self::resolveUrl($url, (string) $result['location']);
                continue;
            }
            if ($result['truncated']) {
                throw new ValidationException('The linked file is larger than 5 MB.', 'recipe.image_too_large');
            }
            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new ValidationException('The link could not be loaded.', 'recipe.ocr_url_fetch_failed');
            }

            return [
                'body' => $result['body'],
                'mime' => (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($result['body']),
                'contentType' => $result['contentType'],
                'url' => $url,
            ];
        }

        throw new ValidationException('The link could not be loaded.', 'recipe.ocr_url_fetch_failed');
    }

    public static function isImage(array $page): bool
    {
        return in_array($page['mime'], self::IMAGE_MIMES, true);
    }

    public static function isHtml(array $page): bool
    {
        return $page['mime'] === 'text/html' || str_contains(strtolower($page['contentType']), 'html');
    }

    /**
     * The page's preview image (`og:image` / `twitter:image`), as an
     * absolute URL, or null.
     */
    public static function previewImageUrl(string $html, string $pageUrl): ?string
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags) < 1) {
            return null;
        }
        $keys = ['og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src'];
        $found = [];
        foreach ($tags[0] as $tag) {
            preg_match_all('/([a-zA-Z][\w:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $attrs, PREG_SET_ORDER);
            $map = [];
            foreach ($attrs as $attr) {
                $map[strtolower($attr[1])] = html_entity_decode($attr[2] !== '' ? $attr[2] : ($attr[3] ?? ''), ENT_QUOTES | ENT_HTML5);
            }
            $key = strtolower($map['property'] ?? $map['name'] ?? '');
            if (in_array($key, $keys, true) && trim($map['content'] ?? '') !== '') {
                $found[$key] ??= trim($map['content']);
            }
        }
        foreach ($keys as $key) {
            if (isset($found[$key])) {
                return self::resolveUrl($pageUrl, $found[$key]);
            }
        }

        return null;
    }

    public static function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative) === 1) {
            return $relative;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($relative, '//')) {
            return $scheme . ':' . $relative;
        }
        if (str_starts_with($relative, '/')) {
            return $origin . $relative;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin . ($dir === '' ? '/' : $dir) . $relative;
    }

    /**
     * @return string the public IP address to connect to
     */
    private function validate(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = $parts['port'] ?? null;
        $invalid = new ValidationException('The link is not a valid web address.', 'recipe.ocr_url_invalid');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw $invalid;
        }
        if ($port !== null && !in_array((int) $port, [80, 443], true)) {
            throw $invalid;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new ValidationException('The link could not be loaded.', 'recipe.ocr_url_fetch_failed');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw $invalid;
            }
        }

        return $ips[0];
    }

    /**
     * Google image-search result links wrap the real picture:
     * https://www.google.com/imgres?imgurl=<image>&imgrefurl=<page>...
     */
    private static function unwrapImageUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1 && ($parts['path'] ?? '') === '/imgres') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (is_string($query['imgurl'] ?? null) && $query['imgurl'] !== '') {
                return $query['imgurl'];
            }
        }

        return $url;
    }

    /**
     * @return string[]
     */
    private static function dnsResolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return array{status:int, contentType:string, location:?string, body:string, truncated:bool}
     */
    private static function curlSender(string $url, string $ip, int $maxBytes): array
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        $body = '';
        $truncated = false;
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinned],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '', // accept gzip/deflate/br, decoded by curl
            // Many sites and image CDNs refuse requests without a browser-like agent.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36 Kochbuch',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.9,*/*;q=0.5',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false && !$truncated) {
            throw new RuntimeException($error);
        }

        return [
            'status' => $status,
            'contentType' => (string) ($headers['content-type'] ?? ''),
            'location' => $headers['location'] ?? null,
            'body' => $body,
            'truncated' => $truncated,
        ];
    }
}
