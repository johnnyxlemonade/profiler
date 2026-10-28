<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture\Metadata;

/**
 * Shares the bounded runtime-text policy across the three metadata sources.
 */
trait SanitizesMetadata
{
    /**
     * Rejects runtime metadata that exceeds the display limit or contains unexpected characters.
     */
    private static function safe(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 160 && preg_match('~^[a-zA-Z0-9_.+ /():-]+$~D', $value) === 1 ? $value : null;
    }
}
