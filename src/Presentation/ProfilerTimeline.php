<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Presentation;

use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use Lemonade\Profiler\Profile;

/**
 * Projects recorded checkpoints and renderer measurements into overlapping, language-neutral intervals.
 * @phpstan-type Interval array{phase:string, start:float, duration:float|null, template?:string}
 */
final readonly class ProfilerTimeline
{
    private const array PAIRS = [
        [HttpCheckpoint::RequestStarted, HttpCheckpoint::ConfigurationLoaded],
        [HttpCheckpoint::BootstrapStarted, HttpCheckpoint::ProvidersBooted],
        [HttpCheckpoint::ProvidersBooted, HttpCheckpoint::RoutesRegistered],
        [HttpCheckpoint::RouteMatchStarted, HttpCheckpoint::RouteMatched],
        [HttpCheckpoint::ControllerResolutionStarted, HttpCheckpoint::ControllerResolved],
        [HttpCheckpoint::ControllerActionStarted, HttpCheckpoint::ControllerActionFinished],
        [HttpCheckpoint::ControllerActionFinished, HttpCheckpoint::ResponseCreated],
    ];

    /**
     * Uses start checkpoint values as phase IDs; missing starts are omitted and missing ends remain null.
     * Renderer intervals retain their measured duration and template, including failed render attempts.
     * @return list<Interval>
     */
    public function intervals(Profile $profile): array
    {
        $data = $profile->toArray();
        $marks = array_column($data['marks'], 'elapsed_ms', 'name');
        $intervals = [];
        foreach (self::PAIRS as [$start, $end]) {
            if (isset($marks[$start->value])) {
                $intervals[] = [
                    'phase' => $start->value,
                    'start' => $marks[$start->value],
                    'duration' => isset($marks[$end->value]) ? max(0.0, $marks[$end->value] - $marks[$start->value]) : null,
                ];
            }
        }
        foreach ($data['renderings'] as $rendering) {
            $intervals[] = [
                'phase' => 'render',
                'start' => $rendering['start_ms'],
                'duration' => $rendering['elapsed_ms'],
                'template' => $rendering['template'],
            ];
        }
        return $intervals;
    }
}
