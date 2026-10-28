<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use RuntimeException;
use Throwable;

/**
 * Renders package-owned PHP templates with escaped snapshot data and isolated styles.
 */
final readonly class ProfilerUi
{
    /**
     * @param list<Profile> $profiles
     */
    public function history(array $profiles): string
    {
        return $this->render('history', $profiles);
    }

    /**
     * Renders the complete detail page or the isolated toolbar document.
     */
    public function profile(Profile $profile, bool $toolbar = false): string
    {
        return $this->render($toolbar ? 'toolbar' : 'request', [], $profile);
    }

    /**
     * Reads the stylesheet from the installed package without publishing assets into the application.
     */
    public function css(): string
    {
        $css = file_get_contents(__DIR__ . '/../resources/profiler.css');
        if ($css === false) {
            throw new RuntimeException('Profiler stylesheet unavailable.');
        }
        return $css;
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
        return number_format($value, 2, '.', '') . ' ms';
    }

    /**
     * Displays the process peak recorded by PHP, in MiB.
     */
    public function memory(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, '.', '') . ' MiB';
    }

    /**
     * Returns measured intervals only; unfinished phases retain a null duration.
     * @return list<array{label:string,start:float,duration:float|null}>
     */
    public function timeline(Profile $profile): array
    {
        $pairs = [
            'request_started' => ['configuration_loaded', 'Lifecycle through configuration'],
            'bootstrap_started' => ['providers_booted', 'Bootstrap and providers'],
            'providers_booted' => ['routes_registered', 'Route registration'],
            'route_match_started' => ['route_matched', 'Dispatch route lookup / reuse'],
            'controller_resolution_started' => ['controller_resolved', 'Controller resolution'],
            'controller_action_started' => ['controller_action_finished', 'Controller action · inclusive'],
            'controller_action_finished' => ['response_created', 'Response normalization'],
        ];
        $marks = [];
        foreach ($profile->data['marks'] as $mark) {
            $marks[$mark['name']] = $mark['elapsed_ms'];
        }
        $intervals = [];
        foreach ($pairs as $start => [$end, $label]) {
            if (isset($marks[$start])) {
                $intervals[] = ['label' => $label, 'start' => $marks[$start], 'duration' => isset($marks[$end]) ? max(0.0, $marks[$end] - $marks[$start]) : null];
            }
        }
        foreach ($profile->data['renderings'] as $rendering) {
            $intervals[] = ['label' => 'Render · ' . $rendering['template'], 'start' => $rendering['start_ms'], 'duration' => $rendering['elapsed_ms']];
        }
        return $intervals;
    }

    /**
     * @param list<Profile> $profiles
     */
    private function render(string $template, array $profiles = [], ?Profile $profile = null): string
    {
        $prefix = ProfilerConfig::PREFIX;
        $level = ob_get_level();
        ob_start();
        try {
            include __DIR__ . '/../resources/' . $template . '.php';
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
