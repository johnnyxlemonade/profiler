<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use InvalidArgumentException;

/**
 * Bounds local capture and storage; development activation is always enforced separately.
 */
final readonly class ProfilerConfig
{
    public const PREFIX = '/_lemonade/profiler';

    /**
     * Creates bounded capture settings without enabling profiling outside development.
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxProfiles = 100,
        public int $retentionSeconds = 3600,
        public int $maxQueries = 200,
        public int $maxSqlLength = 4096,
        public int $maxProfileBytes = 1048576,
        public int $maxRenderings = 200,
        public int $maxHtmlBytes = 2097152,
        public float $slowQueryMs = 100.0,
    ) {
        if (min($maxProfiles, $retentionSeconds, $maxQueries, $maxSqlLength, $maxProfileBytes, $maxRenderings, $maxHtmlBytes) < 1
            || !is_finite($slowQueryMs) || $slowQueryMs < 0) {
            throw new InvalidArgumentException('Profiler limits must be positive and the slow-query threshold finite and non-negative.');
        }
    }
}
