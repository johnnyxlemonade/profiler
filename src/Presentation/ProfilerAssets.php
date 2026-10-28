<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Presentation;

use RuntimeException;

/**
 * Serves only the package assets explicitly exposed by the profiler HTTP endpoints.
 */
final readonly class ProfilerAssets
{
    private const array FILES = [
        'profiler.css' => ['css/profiler.css', 'text/css; charset=utf-8'],
        'toolbar.css' => ['css/toolbar.css', 'text/css; charset=utf-8'],
        'toolbar.js' => ['js/toolbar.js', 'text/javascript; charset=utf-8'],
        'profiler-toolbar.js' => ['js/profiler-toolbar.js', 'text/javascript; charset=utf-8'],
        'request-monitor.js' => ['js/request-monitor.js', 'text/javascript; charset=utf-8'],
        'icons.js' => ['js/icons.js', 'text/javascript; charset=utf-8'],
        'format.js' => ['js/format.js', 'text/javascript; charset=utf-8'],
        'history-filters.js' => ['js/history-filters.js', 'text/javascript; charset=utf-8'],
        'symbol.svg' => ['brand/symbol.svg', 'image/svg+xml'],
    ];

    /**
     * Returns content and MIME type for an exact public asset name, or null for unknown names.
     * @return array{content:string, type:string}|null
     * @throws RuntimeException When an allowlisted package file cannot be read.
     */
    public function load(string $name): ?array
    {
        $asset = self::FILES[$name] ?? null;
        if ($asset === null) {
            return null;
        }
        [$path, $type] = $asset;
        $content = file_get_contents(__DIR__ . '/../../resources/assets/' . $path);
        if ($content === false) {
            throw new RuntimeException('Profiler asset unavailable.');
        }
        return ['content' => $content, 'type' => $type];
    }
}
