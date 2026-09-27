<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Closure;
use RuntimeException;
use Throwable;
use Kochbuch\Exception\ApiException;

/**
 * Wraps Bring!'s (unofficial, undocumented-for-guarantees) shopping-list
 * import API (todo.md "Anbindung der Einkaufs-App Bring!") - a single REST
 * call, no API key required:
 *
 *   POST https://api.getbring.com/rest/bringrecipes/deeplink
 *   {url, source: "web", baseQuantity, requestedQuantity}
 *   -> {deeplink: "https://..."}
 *
 * Bring!'s own server fetches `url` afterwards and parses schema.org
 * Recipe microdata out of it (see templates/recipe-bring-export.php) -
 * this class only handles the deeplink-request round trip itself.
 *
 * The HTTP transport is injectable so tests never hit the real, external
 * api.getbring.com - unlike MailService, which in tests is deliberately
 * pointed at an unreachable SMTP host and tolerates the failure, Bring!'s
 * API is a real third-party service a test suite should never actually call.
 */
final class BringService
{
    private const DEFAULT_API_URL = 'https://api.getbring.com/rest/bringrecipes/deeplink';

    private readonly Closure $sender;

    /**
     * @param (callable(string $apiUrl, array $payload): array{status:int, body:string})|null $sender
     *        Defaults to a real curl-based POST; tests inject a fake that
     *        returns a canned {status, body} pair without any network call.
     */
    public function __construct(
        private readonly string $apiUrl = self::DEFAULT_API_URL,
        ?callable $sender = null,
    ) {
        $this->sender = $sender !== null ? Closure::fromCallable($sender) : self::curlSender(...);
    }

    /**
     * @return string the deeplink Bring! returned, ready to open/redirect to
     */
    public function requestDeeplink(string $url, int $baseQuantity, float $requestedQuantity): string
    {
        $payload = [
            'url' => $url,
            'source' => 'web',
            'baseQuantity' => (string) $baseQuantity,
            'requestedQuantity' => (string) $requestedQuantity,
        ];

        try {
            $result = ($this->sender)($this->apiUrl, $payload);
        } catch (Throwable $e) {
            // Logged rather than silently swallowed (mirrors MailService's
            // own error_log() convention) - the underlying cause (missing
            // curl extension, an outdated/missing CA bundle causing SSL
            // verification failures, an outbound firewall blocking
            // api.getbring.com, ...) is exactly what's needed to diagnose a
            // failure that only reproduces on one particular server, and is
            // otherwise invisible behind the generic message below.
            error_log('Kochbuch: Bring! deeplink request failed: ' . $e->getMessage());
            throw new ApiException('Bring! is currently unavailable.', 502, 'recipe.bring_unavailable');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            error_log('Kochbuch: Bring! deeplink request returned HTTP ' . $result['status'] . ': ' . $result['body']);
            throw new ApiException('Bring! is currently unavailable.', 502, 'recipe.bring_unavailable');
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded) || !is_string($decoded['deeplink'] ?? null) || $decoded['deeplink'] === '') {
            error_log('Kochbuch: Bring! deeplink response had no usable "deeplink": ' . $result['body']);
            throw new ApiException('Bring! is currently unavailable.', 502, 'recipe.bring_unavailable');
        }

        return $decoded['deeplink'];
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
            CURLOPT_TIMEOUT => 5,
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
