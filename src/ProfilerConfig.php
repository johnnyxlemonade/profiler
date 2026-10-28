<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use InvalidArgumentException;

/**
 * Bounds capture and storage; explicit provider registration controls availability.
 */
final readonly class ProfilerConfig
{
    public const PREFIX = '/_lemonade/profiler';

    /**
     * Creates bounded capture settings; profiling is enabled by default when registered.
     */
    public function __construct(
        private bool $enabled = true,
        private int $maxProfiles = 100,
        private int $retentionSeconds = 3600,
        private int $maxQueries = 200,
        private int $maxSqlLength = 4096,
        private int $maxProfileBytes = 1048576,
        private int $maxRenderings = 200,
        private int $maxHtmlBytes = 2097152,
        private float $slowQueryMs = 100.0,
    ) {
        if (min($maxProfiles, $retentionSeconds, $maxQueries, $maxSqlLength, $maxProfileBytes, $maxRenderings, $maxHtmlBytes) < 1
            || !is_finite($slowQueryMs) || $slowQueryMs < 0) {
            throw new InvalidArgumentException('Profiler limits must be positive and the slow-query threshold finite and non-negative.');
        }
    }

    /**
     * Reports whether an explicitly registered provider may capture and serve profiles.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Limits the number of retained snapshots in private storage.
     */
    public function maxProfiles(): int
    {
        return $this->maxProfiles;
    }

    /**
     * Sets the age after which stored snapshots are pruned.
     */
    public function retentionSeconds(): int
    {
        return $this->retentionSeconds;
    }

    /**
     * Bounds the SQL details retained for one request.
     */
    public function maxQueries(): int
    {
        return $this->maxQueries;
    }

    /**
     * Bounds the length of each sanitized SQL statement.
     */
    public function maxSqlLength(): int
    {
        return $this->maxSqlLength;
    }

    /**
     * Caps a serialized snapshot before it is written or read.
     */
    public function maxProfileBytes(): int
    {
        return $this->maxProfileBytes;
    }

    /**
     * Bounds the renderer calls retained for one request.
     */
    public function maxRenderings(): int
    {
        return $this->maxRenderings;
    }

    /**
     * Limits HTML inspection and toolbar injection to bounded response bodies.
     */
    public function maxHtmlBytes(): int
    {
        return $this->maxHtmlBytes;
    }

    /**
     * Marks captured queries at or above this duration as slow.
     */
    public function slowQueryMs(): float
    {
        return $this->slowQueryMs;
    }
}
