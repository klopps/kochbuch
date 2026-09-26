<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\Setting\SettingRepository;
use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Exception\ValidationException;
use Kochbuch\Http\Controllers\SettingsController;

/**
 * No SettingsControllerTest existed yet (only SettingRepositoryTest, which
 * covers the repository directly, not this controller's own validation) -
 * added while touching update()'s validation for the new font-scale fields,
 * per CLAUDE.md's "add a test in the same change" rule.
 */
final class SettingsControllerTest extends ControllerTestCase
{
    private SettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new SettingsController(new SettingRepository($this->pdo));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'Testbuch',
            'default_locale' => 'de',
            'translate_tool_enabled' => true,
            'recipe_page_sizes' => [10, 20, 100],
            'recipe_default_page_size' => 20,
            'default_font_scale_desktop' => 100,
            'default_font_scale_mobile' => 80,
        ], $overrides);
    }

    public function testIndexRequiresAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->index($this->request('GET', '/api/v1/admin/settings', authPayload: $this->authPayload(1)), $this->response());
    }

    public function testUpdateSavesAllFields(): void
    {
        $result = $this->decode($this->controller->update(
            $this->request('PUT', '/api/v1/admin/settings', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: $this->validPayload()),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('Testbuch', $result['data']['app_name']);
        $this->assertSame(100, $result['data']['default_font_scale_desktop']);
        $this->assertSame(80, $result['data']['default_font_scale_mobile']);
    }

    public function testUpdateRejectsAFontScaleBelow50Percent(): void
    {
        try {
            $this->controller->update(
                $this->request('PUT', '/api/v1/admin/settings', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: $this->validPayload(['default_font_scale_mobile' => 49])),
                $this->response()
            );
            $this->fail('Expected a settings.invalid_font_scale ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('settings.invalid_font_scale', $e->getErrorCode());
        }
    }

    public function testUpdateRejectsAFontScaleAbove150Percent(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update(
            $this->request('PUT', '/api/v1/admin/settings', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: $this->validPayload(['default_font_scale_desktop' => 151])),
            $this->response()
        );
    }

    public function testUpdateRejectsAnEmptyAppName(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update(
            $this->request('PUT', '/api/v1/admin/settings', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: $this->validPayload(['app_name' => '  '])),
            $this->response()
        );
    }

    public function testUpdateRejectsADefaultPageSizeNotInTheList(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update(
            $this->request('PUT', '/api/v1/admin/settings', authPayload: $this->authPayload(1, ['is_admin' => true]), jsonBody: $this->validPayload(['recipe_default_page_size' => 999])),
            $this->response()
        );
    }
}
