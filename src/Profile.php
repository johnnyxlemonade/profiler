<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use InvalidArgumentException;
use JsonException;

/**
 * Holds one immutable, validated request snapshot for storage and presentation.
 *
 * @phpstan-type Query array{order:int, sql:string|null, elapsed_ms:float, slow:bool}
 * @phpstan-type Rendering array{order:int, template:string, start_ms:float, elapsed_ms:float, completed:bool}
 * @phpstan-type Mark array{name:string, elapsed_ms:float}
 * @phpstan-type Section array{values:array<string, string|null>, entries:list<array{name:string, version:string|null, dependency:string|null}>}
 * @phpstan-type Data array{diagnostics:array<string, Section>,id:string, created_at:int, method:string, status:int|null, route:string|null, path:string|null, controller:string|null, action:string|null, duration_ms:float, peak_bytes:int, sql_count:int, sql_ms:float, connection_ms:float|null, queries:list<Query>, renderings:list<Rendering>, marks:list<Mark>}
 */
final readonly class Profile
{
    private const array FIELDS = [
        'id', 'created_at', 'method', 'status', 'route', 'path', 'controller', 'action',
        'duration_ms', 'peak_bytes', 'sql_count', 'sql_ms', 'connection_ms',
        'queries', 'renderings', 'marks', 'diagnostics',
    ];

    /**
     * @var Data
     */
    private array $data;

    /**
     * Accepts one current-format snapshot and rejects invalid capture data before storage.
     * @param array<array-key, mixed> $data
     */
    public function __construct(array $data)
    {
        if (!self::valid($data)) {
            throw new InvalidArgumentException('Invalid profiler snapshot.');
        }
        $this->data = $data;
    }

    /**
     * Decodes one current-format JSON snapshot; corrupt or obsolete data is unavailable.
     */
    public static function decode(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        try {
            return new self($data);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Returns the snapshot for serialization and presentation without exposing mutable state.
     * @return Data
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Returns the generated identifier used for private storage and detail URLs.
     */
    public function id(): string
    {
        return $this->data['id'];
    }

    /**
     * Returns the capture timestamp in Unix microseconds for retention ordering.
     */
    public function createdAt(): int
    {
        return $this->data['created_at'];
    }

    /**
     * Validates the current snapshot shape and bounded values at the trust boundary.
     * @phpstan-assert-if-true Data $data
     */
    private static function valid(mixed $data): bool
    {
        if (!is_array($data) || !self::sameKeys($data, self::FIELDS)
            || !is_string($data['id']) || preg_match('/^[a-f0-9]{32}$/D', $data['id']) !== 1
            || !is_string($data['method']) || preg_match('/^[A-Z]{1,16}$/D', $data['method']) !== 1) {
            return false;
        }
        foreach (['route', 'path', 'controller', 'action'] as $key) {
            $value = $data[$key];
            if ($value !== null && (!is_string($value) || strlen($value) > 512 || !mb_check_encoding($value, 'UTF-8'))) {
                return false;
            }
        }
        if (($data['status'] !== null && !is_int($data['status']))
            || !self::nonNegativeInt($data['created_at'])
            || !self::nonNegativeInt($data['peak_bytes'])
            || !self::nonNegativeInt($data['sql_count'])
            || !self::nonNegativeFloat($data['duration_ms'])
            || !self::nonNegativeFloat($data['sql_ms'])
            || ($data['connection_ms'] !== null && !self::nonNegativeFloat($data['connection_ms']))) {
            return false;
        }

        return self::validQueries($data['queries'])
            && self::validRenderings($data['renderings'])
            && self::validMarks($data['marks'])
            && self::validDiagnostics($data['diagnostics']);
    }

    /**
     * Rejects unknown and missing fields without depending on JSON object key order.
     * @param array<array-key, mixed> $data
     * @param list<string> $fields
     */
    private static function sameKeys(array $data, array $fields): bool
    {
        $keys = array_keys($data);
        return array_diff($keys, $fields) === [] && array_diff($fields, $keys) === [];
    }

    /**
     * Accepts only non-negative integer counters and timestamps.
     */
    private static function nonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /**
     * Accepts only finite, non-negative measured durations.
     */
    private static function nonNegativeFloat(mixed $value): bool
    {
        return is_float($value) && is_finite($value) && $value >= 0;
    }

    /**
     * Validates each retained SQL detail without accepting additional fields.
     */
    private static function validQueries(mixed $queries): bool
    {
        if (!is_array($queries) || !array_is_list($queries)) {
            return false;
        }
        foreach ($queries as $query) {
            if (!is_array($query) || !self::sameKeys($query, ['order', 'sql', 'elapsed_ms', 'slow'])
                || !is_int($query['order']) || $query['order'] < 1
                || ($query['sql'] !== null && !is_string($query['sql']))
                || !self::nonNegativeFloat($query['elapsed_ms']) || !is_bool($query['slow'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validates each measured rendering interval and completion flag.
     */
    private static function validRenderings(mixed $renderings): bool
    {
        if (!is_array($renderings) || !array_is_list($renderings)) {
            return false;
        }
        foreach ($renderings as $rendering) {
            if (!is_array($rendering) || !self::sameKeys($rendering, ['order', 'template', 'start_ms', 'elapsed_ms', 'completed'])
                || !is_int($rendering['order']) || $rendering['order'] < 1
                || !is_string($rendering['template']) || strlen($rendering['template']) > 256
                || !self::nonNegativeFloat($rendering['start_ms'])
                || !self::nonNegativeFloat($rendering['elapsed_ms']) || !is_bool($rendering['completed'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validates recorded framework checkpoints without inventing missing marks.
     */
    private static function validMarks(mixed $marks): bool
    {
        if (!is_array($marks) || !array_is_list($marks)) {
            return false;
        }
        foreach ($marks as $mark) {
            if (!is_array($mark) || !self::sameKeys($mark, ['name', 'elapsed_ms'])
                || !is_string($mark['name']) || !self::nonNegativeFloat($mark['elapsed_ms'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Keeps only the allowed diagnostic sections and their bounded value lists.
     */
    private static function validDiagnostics(mixed $sections): bool
    {
        if (!is_array($sections) || array_diff(array_keys($sections), ['session', 'server', 'composer']) !== []) {
            return false;
        }
        foreach ($sections as $section) {
            if (!is_array($section) || !self::sameKeys($section, ['values', 'entries'])
                || !is_array($section['values']) || count($section['values']) > 20
                || !is_array($section['entries']) || !array_is_list($section['entries']) || count($section['entries']) > 500) {
                return false;
            }
            foreach ($section['values'] as $label => $value) {
                if (!is_string($label) || strlen($label) > 80 || preg_match('/^[a-z][a-z0-9_]*$/D', $label) !== 1 || !self::diagnosticValue($value)) {
                    return false;
                }
            }
            foreach ($section['entries'] as $entry) {
                if (!is_array($entry) || !self::sameKeys($entry, ['name', 'version', 'dependency']) || !is_string($entry['name'])) {
                    return false;
                }
                foreach ($entry as $value) {
                    if (!self::diagnosticValue($value)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Rejects diagnostic text that exceeds the display limit or is invalid UTF-8.
     */
    private static function diagnosticValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && strlen($value) <= 160 && mb_check_encoding($value, 'UTF-8'));
    }
}
