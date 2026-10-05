<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Exception\ForbiddenException;
use Kochbuch\Http\Controllers\GeminiQuotaController;
use Kochbuch\Service\GeminiQuotaState;

/**
 * Admin API behind /admin/gemini: shows GeminiQuotaState per configured
 * model, admins only; DELETE resets it.
 */
final class GeminiQuotaControllerTest extends ControllerTestCase
{
    private const NOW = 1791201600; // 2026-10-05 12:00 UTC

    private string $path;
    private GeminiQuotaState $state;
    private GeminiQuotaController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/kochbuch-gemini-quota-' . bin2hex(random_bytes(4)) . '.json';
        $this->state = new GeminiQuotaState($this->path);
        $this->controller = new GeminiQuotaController($this->state, ['a', 'b'], true, fn () => self::NOW);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testRequiresAnAdmin(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/api/v1/admin/gemini-quota', authPayload: $this->authPayload($userId)), $this->response());
    }

    public function testListsConfiguredModelsWithTheirState(): void
    {
        $adminId = $this->createUser();
        $this->state->recordSuccess('b', self::NOW - 600);
        $this->state->recordRateLimit('a', 'day', GeminiQuotaState::nextDailyReset(self::NOW), self::NOW - 60);
        $this->state->recordRateLimit('old-model', 'minute', self::NOW - 1, self::NOW - 100);

        $result = $this->decode($this->controller->index(
            $this->request('GET', '/api/v1/admin/gemini-quota', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response()
        ));

        $data = $result['data'];
        $this->assertTrue($data['key_configured']);
        $this->assertSame(['a', 'b', 'old-model'], array_column($data['models'], 'model'));
        [$a, $b, $old] = $data['models'];
        $this->assertSame('day', $a['status']);
        $this->assertSame(gmdate('c', GeminiQuotaState::nextDailyReset(self::NOW)), $a['exhausted_until']);
        $this->assertSame('available', $b['status']);
        $this->assertSame(1, $b['successes_today']);
        $this->assertSame('available', $old['status'], 'An expired limit shows as available.');
        $this->assertFalse($old['configured']);
        $this->assertSame(gmdate('c', GeminiQuotaState::nextDailyReset(self::NOW)), $data['next_daily_reset']);
    }

    public function testResetClearsTheState(): void
    {
        $adminId = $this->createUser();
        $this->state->recordRateLimit('a', 'day', self::NOW + 3600, self::NOW);

        $result = $this->decode($this->controller->reset(
            $this->request('DELETE', '/api/v1/admin/gemini-quota', authPayload: $this->authPayload($adminId, ['is_admin' => true])),
            $this->response()
        ));

        $this->assertSame('available', $result['data']['models'][0]['status']);
        $this->assertNull($this->state->exhaustedUntil('a', self::NOW));
    }
}
