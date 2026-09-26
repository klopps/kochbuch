<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Domain\Setting\SettingRepository;
use Kochbuch\Exception\ValidationException;

/**
 * Backs the admin "Einstellungen" page (todo.md "Admin-Oberfläche") -
 * app name, default locale, whether the translation tool is active, and
 * the recipe list's configurable page-size options.
 */
final class SettingsController extends BaseController
{
    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->currentSettings()]);
    }

    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        $appName = trim((string) ($body['app_name'] ?? ''));
        if ($appName === '') {
            throw new ValidationException('App name is required.', 'settings.app_name_required');
        }

        $pageSizes = array_values(array_unique(array_filter(
            array_map('intval', is_array($body['recipe_page_sizes'] ?? null) ? $body['recipe_page_sizes'] : []),
            static fn (int $n) => $n > 0
        )));
        sort($pageSizes);
        if ($pageSizes === []) {
            throw new ValidationException('Please provide at least one valid page size.', 'settings.invalid_page_sizes');
        }

        $defaultPageSize = (int) ($body['recipe_default_page_size'] ?? 0);
        if (!in_array($defaultPageSize, $pageSizes, true)) {
            throw new ValidationException('Default page size must be one of the available page sizes.', 'settings.default_page_size_not_in_list');
        }

        $defaultLocale = trim((string) ($body['default_locale'] ?? '')) ?: 'de';

        $fontScaleDesktop = (int) ($body['default_font_scale_desktop'] ?? 0);
        $fontScaleMobile = (int) ($body['default_font_scale_mobile'] ?? 0);
        if ($fontScaleDesktop < 50 || $fontScaleDesktop > 150 || $fontScaleMobile < 50 || $fontScaleMobile > 150) {
            throw new ValidationException('Font scale must be between 50% and 150%.', 'settings.invalid_font_scale');
        }

        $this->settings->setMany([
            'app_name' => $appName,
            'default_locale' => $defaultLocale,
            'translate_tool_enabled' => !empty($body['translate_tool_enabled']) ? '1' : '0',
            'recipe_page_sizes' => implode(',', $pageSizes),
            'recipe_default_page_size' => (string) $defaultPageSize,
            'default_font_scale_desktop' => (string) $fontScaleDesktop,
            'default_font_scale_mobile' => (string) $fontScaleMobile,
        ]);

        return $this->json($response, ['data' => $this->currentSettings()]);
    }

    private function currentSettings(): array
    {
        return [
            'app_name' => $this->settings->get('app_name', 'Kochbuch'),
            'default_locale' => $this->settings->get('default_locale', 'de'),
            'translate_tool_enabled' => $this->settings->getBool('translate_tool_enabled'),
            'recipe_page_sizes' => $this->settings->getIntList('recipe_page_sizes', [10, 20, 100]),
            'recipe_default_page_size' => $this->settings->getInt('recipe_default_page_size', 10),
            'default_font_scale_desktop' => $this->settings->getInt('default_font_scale_desktop', 100),
            'default_font_scale_mobile' => $this->settings->getInt('default_font_scale_mobile', 80),
        ];
    }
}
