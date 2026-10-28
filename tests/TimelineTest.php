<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfilerLocale;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerTranslator;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use PHPUnit\Framework\TestCase;

final class TimelineTest extends TestCase
{
    public function testDurationFormattingKeepsZeroAndSmallPositiveMeasurementsDistinct(): void
    {
        $ui = new ProfilerUi(new ProfilerTimeline());
        self::assertSame('0 µs', $ui->ms(0.0));
        self::assertSame('<0.01 µs', $ui->ms(0.000001));
        self::assertSame('0.01 µs', $ui->ms(0.000006));
        self::assertSame('7.92 µs', $ui->ms(0.00792));
        self::assertSame('120.26 µs', $ui->ms(0.12026));
        self::assertSame('999.99 µs', $ui->ms(0.99999));
        self::assertSame('1.00 ms', $ui->ms(1.0));
    }

    public function testRecordedIntervalsKeepTheirIdentityOverlapAndTranslations(): void
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        foreach ([
            [HttpCheckpoint::RequestStarted, 0.0],
            [HttpCheckpoint::ConfigurationLoaded, 2.0],
            [HttpCheckpoint::BootstrapStarted, 2.0],
            [HttpCheckpoint::ProvidersBooted, 5.0],
            [HttpCheckpoint::RoutesRegistered, 6.0],
            [HttpCheckpoint::RouteMatchStarted, 7.0],
            [HttpCheckpoint::RouteMatched, 8.0],
            [HttpCheckpoint::ControllerResolutionStarted, 8.0],
            [HttpCheckpoint::ControllerResolved, 9.0],
            [HttpCheckpoint::ControllerActionStarted, 9.0],
            [HttpCheckpoint::ControllerActionFinished, 20.0],
            [HttpCheckpoint::ResponseCreated, 22.0],
        ] as [$checkpoint, $elapsed]) {
            $context->checkpoint($checkpoint, $elapsed);
        }
        $context->completed(new HttpMetrics(22.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        $data['renderings'] = [
            ['order' => 1, 'template' => 'outer', 'start_ms' => 10.0, 'elapsed_ms' => 4.0, 'completed' => true],
            ['order' => 2, 'template' => 'inner', 'start_ms' => 11.0, 'elapsed_ms' => 1.0, 'completed' => false],
        ];
        $profile = new Profile($data);
        $timeline = new ProfilerTimeline();
        $intervals = $timeline->intervals($profile);
        self::assertSame([
            'request_started', 'bootstrap_started', 'providers_booted', 'route_match_started',
            'controller_resolution_started', 'controller_action_started', 'controller_action_finished', 'render', 'render',
        ], array_column($intervals, 'phase'));
        self::assertSame([0.0, 2.0, 5.0, 7.0, 8.0, 9.0, 20.0, 10.0, 11.0], array_column($intervals, 'start'));
        self::assertSame([2.0, 3.0, 1.0, 1.0, 1.0, 11.0, 2.0, 4.0, 1.0], array_column($intervals, 'duration'));
        self::assertSame(['outer', 'inner'], array_column($intervals, 'template'));
        $toolbar = (new ProfilerUi($timeline))->toolbarData($profile);
        $breakdown = (new ProfilerUi($timeline))->timeBreakdown($profile);
        $byPhase = array_column($breakdown, 'duration', 'phase');
        self::assertSame(4.0, $byPhase['render']);
        self::assertSame(7.0, $byPhase['controller_action_started']);
        self::assertSame(21.0, array_sum($byPhase));
        self::assertLessThanOrEqual(100.0, array_sum(array_column($breakdown, 'percent')));
        foreach ($toolbar['phases'] as $index => $phase) {
            self::assertSame($intervals[$index], array_diff_key($phase, ['label_key' => true]));
            foreach (ProfilerLocale::cases() as $locale) {
                self::assertNotSame($phase['label_key'], (new ProfilerTranslator($locale))->translate($phase['label_key']));
            }
        }
        self::assertSame($data, $profile->toArray());
    }

    public function testAbsentStartsAreNotInventedAndMissingEndsStayNull(): void
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->checkpoint(HttpCheckpoint::ConfigurationLoaded, 2.0);
        $context->checkpoint(HttpCheckpoint::RouteMatchStarted, 3.0);
        $context->completed(new HttpMetrics(4.0, 1024, 0, 0.0));
        $profile = $context->snapshot('GET', 500, null);
        self::assertSame([
            ['phase' => 'route_match_started', 'start' => 3.0, 'duration' => null],
        ], (new ProfilerTimeline())->intervals($profile));
        self::assertSame([], (new ProfilerUi(new ProfilerTimeline()))->timeBreakdown($profile));
    }
}
