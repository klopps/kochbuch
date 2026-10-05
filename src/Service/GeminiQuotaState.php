<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Remembers Gemini's rate limits per model across requests (todo.md
 * "Gemini-Ratenlimits erkennen"), in a small JSON file outside the webroot
 * (storage/gemini-quota.json - storage/ is never part of a deploy, so the
 * state survives deploys):
 *
 *  - which model is exhausted until when, and whether by its per-minute or
 *    per-day quota - GeminiRecipeExtractor skips such a model instead of
 *    asking it again and again,
 *  - per model and Gemini day: requests sent and successful ones, plus the
 *    time of the last success / last rate limit - shown on the admin page
 *    /admin/gemini, so one can see how close to the daily limit the app is.
 *
 * A "Gemini day" is a calendar day in Pacific time: the free tier's daily
 * quotas reset at midnight America/Los_Angeles (09:00 in Germany during
 * summer time). A null path disables persistence (tests, or no key set).
 */
final class GeminiQuotaState
{
    private const RESET_TIMEZONE = 'America/Los_Angeles';

    public function __construct(private readonly ?string $path)
    {
    }

    /**
     * @return array{kind: string, until: int}|null
     */
    public function exhaustedUntil(string $model, int $now): ?array
    {
        $entry = $this->read()['models'][$model] ?? null;
        if (!is_array($entry) || !isset($entry['exhausted_until']) || (int) $entry['exhausted_until'] <= $now) {
            return null;
        }

        return ['kind' => (string) ($entry['exhausted_kind'] ?? 'minute'), 'until' => (int) $entry['exhausted_until']];
    }

    public function recordSuccess(string $model, int $now): void
    {
        $this->update($model, $now, static function (array $entry) use ($now): array {
            $entry['requests_today']++;
            $entry['successes_today']++;
            $entry['last_success_at'] = $now;
            $entry['exhausted_until'] = null;
            $entry['exhausted_kind'] = null;

            return $entry;
        });
    }

    public function recordRateLimit(string $model, string $kind, int $until, int $now): void
    {
        $this->update($model, $now, static function (array $entry) use ($kind, $until, $now): array {
            $entry['requests_today']++;
            $entry['last_limit_at'] = $now;
            $entry['last_limit_kind'] = $kind;
            $entry['exhausted_until'] = $until;
            $entry['exhausted_kind'] = $kind;

            return $entry;
        });
    }

    /**
     * @return array<string, array<string, mixed>> model => entry, with the
     *         daily counters already reset when a new Gemini day has begun
     */
    public function all(int $now): array
    {
        $models = [];
        foreach ($this->read()['models'] ?? [] as $model => $entry) {
            if (is_array($entry)) {
                $models[(string) $model] = self::forToday($entry, $now);
            }
        }

        return $models;
    }

    public function reset(): void
    {
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public static function geminiDay(int $now): string
    {
        return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(self::RESET_TIMEZONE))->format('Y-m-d');
    }

    /**
     * The next midnight in Pacific time - when the daily quotas reset.
     */
    public static function nextDailyReset(int $now): int
    {
        return (new DateTimeImmutable('@' . $now))
            ->setTimezone(new DateTimeZone(self::RESET_TIMEZONE))
            ->modify('tomorrow')
            ->getTimestamp();
    }

    private static function forToday(array $entry, int $now): array
    {
        $entry += [
            'day' => null,
            'requests_today' => 0,
            'successes_today' => 0,
            'last_success_at' => null,
            'last_limit_at' => null,
            'last_limit_kind' => null,
            'exhausted_until' => null,
            'exhausted_kind' => null,
        ];
        if ($entry['day'] !== self::geminiDay($now)) {
            $entry['day'] = self::geminiDay($now);
            $entry['requests_today'] = 0;
            $entry['successes_today'] = 0;
        }

        return $entry;
    }

    private function read(): array
    {
        if ($this->path === null || !is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read-modify-write under an exclusive lock, so two simultaneous
     * imports can't lose each other's counter updates.
     */
    private function update(string $model, int $now, callable $change): void
    {
        if ($this->path === null) {
            return;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            error_log('Kochbuch: cannot write ' . $this->path);

            return;
        }
        try {
            flock($handle, LOCK_EX);
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $data = is_array($decoded) ? $decoded : [];
            $entry = is_array($data['models'][$model] ?? null) ? $data['models'][$model] : [];
            $data['models'][$model] = $change(self::forToday($entry, $now));
            $data['updated_at'] = $now;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
