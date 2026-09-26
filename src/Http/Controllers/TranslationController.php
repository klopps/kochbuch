<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Service\TranslationRepository;
use Kochbuch\Service\TranslationUsageScanner;

/**
 * Backs the admin "/admin/translate" tool - only registered in App.php at
 * all when the "translate_tool_enabled" setting is on (see
 * SettingRepository), since a bad write here affects every page load for
 * every visitor. requireAdmin() is a second, independent gate on top of
 * that (mirrors YTAN's TRANSLATE_TOOL_ENABLED double-gate).
 */
final class TranslationController extends BaseController
{
    public function __construct(
        private readonly TranslationRepository $translations,
        private readonly TranslationUsageScanner $scanner,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => [
            'en' => $this->translations->load('en'),
            'de' => $this->translations->load('de'),
            'usage' => $this->scanner->scan(),
        ]]);
    }

    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        $en = $body['en'] ?? null;
        $de = $body['de'] ?? null;
        if (!is_array($en) || !is_array($de)) {
            throw new ValidationException('Both "en" and "de" translation maps are required.', 'translation.maps_required');
        }
        foreach (array_merge($en, $de) as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new ValidationException('Translation keys and values must be strings.', 'translation.invalid_shape');
            }
        }

        $this->translations->save($en, $de, !empty($body['force']));

        return $this->json($response, ['data' => ['message' => 'Translations saved.']]);
    }
}
