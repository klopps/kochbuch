<?php

declare(strict_types=1);

namespace Kochbuch\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Kochbuch\Service\GeminiQuotaState;

/**
 * Admin view of Gemini's rate-limit state (todo.md "Gemini-Ratenlimits
 * erkennen", page /admin/gemini): per configured model whether it is
 * currently usable or exhausted by its per-minute / per-day quota (and
 * until when), plus today's request counters - all from GeminiQuotaState's
 * storage/gemini-quota.json. DELETE forgets the remembered state, e.g.
 * after the quota was raised.
 */
final class GeminiQuotaController extends BaseController
{
    private readonly \Closure $clock;

    /**
     * @param string[] $models configured model list (GEMINI_MODEL), in order
     */
    public function __construct(
        private readonly GeminiQuotaState $state,
        private readonly array $models,
        private readonly bool $keyConfigured,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->snapshot()]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $this->state->reset();

        return $this->json($response, ['data' => $this->snapshot()]);
    }

    private function snapshot(): array
    {
        $now = ($this->clock)();
        $stored = $this->state->all($now);
        // Configured models first (in their fallback order), then any model
        // that only still appears in the stored state (e.g. removed from
        // GEMINI_MODEL since).
        $names = array_values(array_unique(array_merge($this->models, array_keys($stored))));

        $rows = [];
        foreach ($names as $name) {
            $entry = $stored[$name] ?? [];
            $exhaustedUntil = isset($entry['exhausted_until']) ? (int) $entry['exhausted_until'] : null;
            $limited = $exhaustedUntil !== null && $exhaustedUntil > $now;
            $rows[] = [
                'model' => $name,
                'configured' => in_array($name, $this->models, true),
                'status' => $limited ? (string) ($entry['exhausted_kind'] ?? 'minute') : 'available',
                'exhausted_until' => $limited ? self::iso($exhaustedUntil) : null,
                'requests_today' => (int) ($entry['requests_today'] ?? 0),
                'successes_today' => (int) ($entry['successes_today'] ?? 0),
                'last_success_at' => self::iso($entry['last_success_at'] ?? null),
                'last_limit_at' => self::iso($entry['last_limit_at'] ?? null),
                'last_limit_kind' => $entry['last_limit_kind'] ?? null,
            ];
        }

        return [
            'key_configured' => $this->keyConfigured,
            'gemini_day' => GeminiQuotaState::geminiDay($now),
            'next_daily_reset' => self::iso(GeminiQuotaState::nextDailyReset($now)),
            'models' => $rows,
        ];
    }

    private static function iso(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) ? gmdate('c', (int) $timestamp) : null;
    }
}
