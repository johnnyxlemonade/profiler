<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use JsonException;

/**
 * Carries the bounded, allowlisted snapshot shared by storage and presentation.
 *
 * @phpstan-type Query array{order:int, sql:string|null, elapsed_ms:float, slow:bool}
 * @phpstan-type Rendering array{order:int, template:string, start_ms:float, elapsed_ms:float, completed:bool}
 * @phpstan-type Mark array{name:string, elapsed_ms:float}
 * @phpstan-type Data array{id:string, created_at:int, method:string, status:int|null, route:string|null, path:string|null, controller:string|null, action:string|null, duration_ms:float, peak_bytes:int, sql_count:int, sql_ms:float, connection_ms:float|null, queries:list<Query>, renderings:list<Rendering>, marks:list<Mark>}
 */
final readonly class Profile
{
    /**
     * Accepts only the profiler's public snapshot schema.
     * @param Data $data
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * Decodes stored snapshots only when their complete schema is valid.
     */
    public static function decode(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        // Read historical snapshots without reviving their former measuring lifecycle.
        if (is_array($data) && count($data) === 17
            && isset($data['benchmark_ms'], $data['early_sql_count'])
            && is_float($data['benchmark_ms']) && is_finite($data['benchmark_ms']) && $data['benchmark_ms'] >= 0
            && is_int($data['early_sql_count']) && $data['early_sql_count'] >= 0) {
            unset($data['benchmark_ms'], $data['early_sql_count']);
            $data['connection_ms'] = null;
            $names = [
                'kernel_start' => 'request_started',
                'config_loaded' => 'configuration_loaded',
                'bootstrap_start' => 'bootstrap_started',
                'route_match_start' => 'route_match_started',
                'controller_resolve_start' => 'controller_resolution_started',
                'controller_action_start' => 'controller_action_started',
            ];
            if (is_array($data['marks'] ?? null)) {
                foreach ($data['marks'] as &$mark) {
                    if (is_array($mark) && is_string($mark['name'] ?? null)) {
                        $mark['name'] = $names[$mark['name']] ?? $mark['name'];
                    }
                }
                unset($mark);
            }
        }
        return self::valid($data) ? new self($data) : null;
    }

    /**
     * Validates the storage boundary before exposing a profile to the UI.
     * @phpstan-assert-if-true Data $data
     */
    private static function valid(mixed $data): bool
    {
        if (!is_array($data) || count($data) !== 16) {
            return false;
        }
        foreach (['id', 'method'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return false;
            }
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $data['id']) !== 1) {
            return false;
        }
        foreach (['route', 'path', 'controller', 'action'] as $key) {
            if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_string($data[$key]))) {
                return false;
            }
        }
        if (!array_key_exists('status', $data) || ($data['status'] !== null && !is_int($data['status']))) {
            return false;
        }
        foreach (['created_at', 'peak_bytes', 'sql_count'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 0) {
                return false;
            }
        }
        if (!array_key_exists('connection_ms', $data) || ($data['connection_ms'] !== null
            && (!is_float($data['connection_ms']) || !is_finite($data['connection_ms']) || $data['connection_ms'] < 0))) {
            return false;
        }
        foreach (['duration_ms', 'sql_ms'] as $key) {
            if (!isset($data[$key]) || !is_float($data[$key]) || !is_finite($data[$key]) || $data[$key] < 0) {
                return false;
            }
        }
        foreach (['queries', 'renderings', 'marks'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
                return false;
            }
            foreach ($data[$key] as $row) {
                if (!is_array($row) || !isset($row['elapsed_ms']) || !is_float($row['elapsed_ms']) || !is_finite($row['elapsed_ms']) || $row['elapsed_ms'] < 0) {
                    return false;
                }
                if ($key === 'marks') {
                    if (count($row) !== 2 || !is_string($row['name'] ?? null)) {
                        return false;
                    }
                } elseif (!is_int($row['order'] ?? null)) {
                    return false;
                } elseif ($key === 'queries') {
                    if (count($row) !== 4 || !array_key_exists('sql', $row) || ($row['sql'] !== null && !is_string($row['sql'])) || !is_bool($row['slow'] ?? null)) {
                        return false;
                    }
                } elseif (count($row) !== 5 || !is_string($row['template'] ?? null) || !is_bool($row['completed'] ?? null)
                    || !is_float($row['start_ms'] ?? null) || !is_finite($row['start_ms']) || $row['start_ms'] < 0) {
                    return false;
                }
            }
        }
        return true;
    }
}
