<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Presentation;

use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;

use Throwable;

/**
 * Renders package templates and prepares bounded toolbar data from immutable snapshots.
 */
final readonly class ProfilerUi
{
    /**
     * Shares the measured interval projection between request detail and toolbar data.
     */
    public function __construct(private ProfilerTimeline $timeline) {}

    /**
     * Renders supplied profiles in their given order as a localized standalone page.
     *
     * @param list<Profile> $profiles
     */
    public function history(array $profiles, ProfilerLocale $locale = ProfilerLocale::En): string
    {
        return $this->render(template: 'history', profiles: $profiles, locale: $locale);
    }

    /**
     * Renders the complete standalone request detail page.
     *
     * @param list<Profile> $history
     */
    public function profile(Profile $profile, ProfilerLocale $locale = ProfilerLocale::En, array $history = []): string
    {
        return $this->render(template: 'request', profiles: $history, profile: $profile, locale: $locale);
    }

    /**
     * Compares one completed profile with retained earlier profiles of the same named route and method.
     * Stored Profile values have already passed validation, including finite non-negative metric totals.
     *
     * @param list<Profile> $history
     * @return array{count:int, average_duration_ms:float, duration_percent:float|null, average_sql_count:float, sql_difference:float, average_sql_ms:float}|null
     */
    public function compare(Profile $current, array $history): ?array
    {
        $data = $current->toArray();
        if ($data['route'] === null || $data['route'] === '' || $data['status'] === null
            || $data['status'] < 100 || $data['status'] > 599) {
            return null;
        }

        $count = 0;
        $averageDuration = 0.0;
        $averageSqlCount = 0.0;
        $averageSqlMs = 0.0;
        foreach ($history as $candidate) {
            $previous = $candidate->toArray();
            if ($previous['id'] === $data['id'] || $previous['created_at'] > $data['created_at']
                || $previous['route'] !== $data['route'] || $previous['method'] !== $data['method']
                || $previous['status'] === null || $previous['status'] < 100 || $previous['status'] > 599) {
                continue;
            }

            ++$count;
            $averageDuration += ($previous['duration_ms'] - $averageDuration) / $count;
            $averageSqlCount += ($previous['sql_count'] - $averageSqlCount) / $count;
            $averageSqlMs += ($previous['sql_ms'] - $averageSqlMs) / $count;
        }

        if ($count < 5) {
            return null;
        }

        $durationPercent = $averageDuration > 0.0
            ? (($data['duration_ms'] - $averageDuration) / $averageDuration) * 100
            : null;
        return [
            'count' => $count,
            'average_duration_ms' => $averageDuration,
            'duration_percent' => $durationPercent !== null && is_finite($durationPercent) ? $durationPercent : null,
            'average_sql_count' => $averageSqlCount,
            'sql_difference' => $data['sql_count'] - $averageSqlCount,
            'average_sql_ms' => $averageSqlMs,
        ];
    }

    /**
     * Formats arithmetic averages and differences in query counts without rounding to integers.
     */
    public function queryAverage(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        if ($value > 0.0 && $value < 0.01) {
            return '<0.01';
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Allocates only observed, completed interval portions to a non-overlapping chart.
     * Rendering takes precedence over inclusive controller time; uncovered time stays unassigned.
     *
     * @return list<array{phase:string, duration:float, percent:float}>
     */
    public function timeBreakdown(Profile $profile): array
    {
        $total = $profile->toArray()['duration_ms'];
        if ($total <= 0) {
            return [];
        }

        $intervals = $this->timeline->intervals($profile);
        usort($intervals, static fn(array $a, array $b): int => ($b['phase'] === 'render' ? 1 : 0) <=> ($a['phase'] === 'render' ? 1 : 0));
        $occupied = [];
        $durations = [];
        foreach ($intervals as $interval) {
            if ($interval['duration'] === null || $interval['duration'] <= 0) {
                continue;
            }
            $start = max(0.0, min($total, $interval['start']));
            $end = max($start, min($total, $interval['start'] + $interval['duration']));
            $pieces = [[$start, $end]];
            foreach ($occupied as [$usedStart, $usedEnd]) {
                $remaining = [];
                foreach ($pieces as [$pieceStart, $pieceEnd]) {
                    if ($usedEnd <= $pieceStart || $usedStart >= $pieceEnd) {
                        $remaining[] = [$pieceStart, $pieceEnd];
                        continue;
                    }
                    if ($usedStart > $pieceStart) {
                        $remaining[] = [$pieceStart, $usedStart];
                    }
                    if ($usedEnd < $pieceEnd) {
                        $remaining[] = [$usedEnd, $pieceEnd];
                    }
                }
                $pieces = $remaining;
            }
            foreach ($pieces as [$pieceStart, $pieceEnd]) {
                if ($pieceEnd > $pieceStart) {
                    $occupied[] = [$pieceStart, $pieceEnd];
                    $durations[$interval['phase']] = ($durations[$interval['phase']] ?? 0.0) + $pieceEnd - $pieceStart;
                }
            }
        }

        $breakdown = [];
        foreach ($durations as $phase => $duration) {
            $breakdown[] = ['phase' => $phase, 'duration' => $duration, 'percent' => 100 * $duration / $total];
        }
        return $breakdown;
    }

    /**
     * Returns only already measured totals and static route metadata for response headers.
     * @return array<string, int|float|string|null>
     */
    public function summary(Profile $profile): array
    {
        return array_intersect_key($profile->toArray(), array_flip([
            'id', 'method', 'status', 'route', 'path', 'duration_ms', 'peak_bytes', 'sql_count', 'sql_ms',
        ]));
    }

    /**
     * Supplies bounded, sanitized details to the current page without another network request.
     * @return array<string, mixed>
     */
    public function toolbarData(Profile $profile): array
    {
        $data = $profile->toArray();
        $longest = null;
        foreach ($data['queries'] as $query) {
            if ($longest === null || $query['elapsed_ms'] > $longest['elapsed_ms']) {
                $longest = $query;
            }
        }
        $runtime = $data['diagnostics']['server']['values'] ?? [];
        $server = [];
        foreach ([
            'php_version', 'php_sapi', 'memory_limit', 'max_execution_time', 'timezone',
            'web_server', 'os', 'environment', 'framework_version',
        ] as $name) {
            $server[$name] = $runtime[$name] ?? null;
        }
        return $this->summary($profile) + [
            'server' => $server,
            'controller' => $data['controller'],
            'action' => $data['action'],
            'phases' => array_slice($this->timelineData($profile), 0, 12),
            'longest' => $longest,
            'i18n' => ProfilerTranslator::dictionaries(),
        ];
    }

    /**
     * Escapes all dynamic template text and attribute values.
     */
    public function escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string) ($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Formats durations consistently without discarding sub-millisecond measurements.
     */
    public function ms(float $value): string
    {
        if ($value === 0.0) {
            return '0 µs';
        }
        if ($value >= 1.0) {
            return number_format($value, 2, '.', '') . ' ms';
        }
        $microseconds = number_format($value * 1000, 2, '.', '');
        return $microseconds === '0.00' ? '<0.01 µs' : $microseconds . ' µs';
    }

    /**
     * Displays the process peak recorded by PHP, in MiB.
     */
    public function memory(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, '.', '') . ' MiB';
    }

    /**
     * Attaches dictionary keys for PHP and JavaScript without translating stored measurements.
     * @return list<array{phase:string, start:float, duration:float|null, template?:string, label_key:string}>
     */
    private function timelineData(Profile $profile): array
    {
        return array_map(
            static fn(array $interval): array => $interval + ['label_key' => ProfilerTranslator::phaseKey($interval['phase'])],
            $this->timeline->intervals($profile),
        );
    }

    /**
     * Renders a package-owned template and restores the output buffer after failures.
     *
     * @param list<Profile> $profiles
     */
    private function render(
        string $template,
        array $profiles = [],
        ?Profile $profile = null,
        ProfilerLocale $locale = ProfilerLocale::En,
    ): string {
        $prefix = ProfilerConfig::PREFIX;
        $translator = new ProfilerTranslator($locale);
        $localeCode = $locale->value;
        $cs = ProfilerLocale::Cs->value;
        $en = ProfilerLocale::En->value;
        $tr = fn(string $key, array $parameters = []): string => $this->escape($translator->translate($key, $parameters));
        $data = $profile?->toArray();
        $intervals = $profile === null ? [] : $this->timelineData($profile);
        $breakdown = $profile === null ? [] : $this->timeBreakdown($profile);
        $comparison = $profile === null ? null : $this->compare($profile, $profiles);
        $level = ob_get_level();
        ob_start();
        try {
            include __DIR__ . '/../../resources/views/' . $template . '.php';
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
