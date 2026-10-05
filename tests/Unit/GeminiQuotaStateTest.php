<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use Kochbuch\Service\GeminiQuotaState;
use PHPUnit\Framework\TestCase;

/**
 * GeminiQuotaState's file-backed per-model memory of rate limits and daily
 * counters (todo.md "Gemini-Ratenlimits erkennen").
 */
final class GeminiQuotaStateTest extends TestCase
{
    // 2026-10-05 12:00 UTC = 05:00 Pacific (PDT) - well inside one Gemini day.
    private const NOW = 1791201600;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kochbuch-gemini-quota-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testRemembersARateLimitUntilItExpires(): void
    {
        $state = new GeminiQuotaState($this->path);
        $state->recordRateLimit('a', 'minute', self::NOW + 40, self::NOW);

        $this->assertSame(['kind' => 'minute', 'until' => self::NOW + 40], $state->exhaustedUntil('a', self::NOW + 10));
        $this->assertNull($state->exhaustedUntil('a', self::NOW + 40));
        $this->assertNull($state->exhaustedUntil('b', self::NOW));
        // A second instance reads the same file - the state survives requests.
        $this->assertNotNull((new GeminiQuotaState($this->path))->exhaustedUntil('a', self::NOW + 1));
    }

    public function testCountsRequestsPerGeminiDayAndResetsThemOnTheNextDay(): void
    {
        $state = new GeminiQuotaState($this->path);
        $state->recordSuccess('a', self::NOW);
        $state->recordSuccess('a', self::NOW + 60);
        $state->recordRateLimit('a', 'day', GeminiQuotaState::nextDailyReset(self::NOW), self::NOW + 120);

        $today = $state->all(self::NOW + 180)['a'];
        $this->assertSame(3, $today['requests_today']);
        $this->assertSame(2, $today['successes_today']);
        $this->assertSame(self::NOW + 60, $today['last_success_at']);
        $this->assertSame('day', $today['last_limit_kind']);

        $tomorrow = $state->all(self::NOW + 86400)['a'];
        $this->assertSame(0, $tomorrow['requests_today']);
        $this->assertSame(self::NOW + 60, $tomorrow['last_success_at']);
    }

    public function testASuccessClearsTheRememberedLimit(): void
    {
        $state = new GeminiQuotaState($this->path);
        $state->recordRateLimit('a', 'minute', self::NOW + 600, self::NOW);
        $state->recordSuccess('a', self::NOW + 5);

        $this->assertNull($state->exhaustedUntil('a', self::NOW + 10));
    }

    public function testResetForgetsEverything(): void
    {
        $state = new GeminiQuotaState($this->path);
        $state->recordRateLimit('a', 'day', self::NOW + 3600, self::NOW);
        $state->reset();

        $this->assertSame([], $state->all(self::NOW));
        $this->assertNull($state->exhaustedUntil('a', self::NOW));
    }

    public function testDailyResetIsMidnightPacificTime(): void
    {
        // 05:00 PDT on 2026-10-05 -> next reset 2026-10-06 00:00 PDT = 07:00 UTC.
        $this->assertSame(gmmktime(7, 0, 0, 10, 6, 2026), GeminiQuotaState::nextDailyReset(self::NOW));
        $this->assertSame('2026-10-05', GeminiQuotaState::geminiDay(self::NOW));
    }

    public function testNullPathPersistsNothing(): void
    {
        $state = new GeminiQuotaState(null);
        $state->recordRateLimit('a', 'minute', self::NOW + 40, self::NOW);

        $this->assertNull($state->exhaustedUntil('a', self::NOW));
    }
}
