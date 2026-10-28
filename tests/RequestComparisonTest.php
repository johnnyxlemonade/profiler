<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use PHPUnit\Framework\TestCase;

final class RequestComparisonTest extends TestCase
{
    public function testOnlyEarlierCompletedProfilesOfTheSameNamedRouteAndMethodAreCompared(): void
    {
        $current = $this->profile(10, 100, duration: 12.0, sqlCount: 8, sqlMs: 4.0);
        $history = [
            $this->profile(1, 10, duration: 8.0, sqlCount: 2, sqlMs: 1.0),
            $this->profile(2, 20, duration: 9.0, sqlCount: 3, sqlMs: 2.0),
            $this->profile(3, 30, duration: 10.0, sqlCount: 4, sqlMs: 3.0),
            $this->profile(4, 40, duration: 11.0, sqlCount: 5, sqlMs: 4.0),
            $this->profile(5, 50, duration: 12.0, sqlCount: 6, sqlMs: 5.0),
            $this->profile(6, 60, route: 'other.route', duration: 100.0),
            $this->profile(7, 70, method: 'POST', duration: 100.0),
            $this->profile(8, 110, duration: 100.0),
            $this->profile(9, 80, status: null, duration: 100.0),
            $this->profile(13, 85, status: 0, duration: 100.0),
            $current,
        ];

        $comparison = $this->ui()->compare($current, $history);
        self::assertNotNull($comparison);
        self::assertSame(5, $comparison['count']);
        self::assertSame(10.0, $comparison['average_duration_ms']);
        self::assertEqualsWithDelta(20.0, $comparison['duration_percent'], 0.000001);
        self::assertSame(4.0, $comparison['average_sql_count']);
        self::assertSame(4.0, $comparison['sql_difference']);
        self::assertSame(3.0, $comparison['average_sql_ms']);
    }

    public function testAtLeastFiveAvailableSamplesAreRequiredAndRoutePathIsNotAReplacement(): void
    {
        $current = $this->profile(10, 100);
        $previous = [];
        for ($index = 1; $index <= 5; ++$index) {
            $previous[] = $this->profile($index, $index);
        }

        self::assertNull($this->ui()->compare($current, []));
        self::assertNull($this->ui()->compare($current, array_slice($previous, 0, 4)));
        $comparison = $this->ui()->compare($current, $previous);
        self::assertNotNull($comparison);
        self::assertSame(5, $comparison['count']);

        $unnamed = $this->profile(11, 100, route: null);
        $unnamedHistory = array_map(fn(Profile $profile): Profile => $this->withRoute($profile, null), $previous);
        self::assertNull($this->ui()->compare($unnamed, $unnamedHistory));
        self::assertNull($this->ui()->compare($this->profile(12, 100, status: null), $previous));
    }

    public function testFasterSlowerAndUnchangedDurationsUseTheArithmeticAverage(): void
    {
        $previous = [];
        for ($index = 1; $index <= 5; ++$index) {
            $previous[] = $this->profile($index, $index, duration: 10.0, sqlCount: 6, sqlMs: 2.0);
        }

        $faster = $this->ui()->compare($this->profile(10, 100, duration: 8.0, sqlCount: 4), $previous);
        $slower = $this->ui()->compare($this->profile(11, 100, duration: 12.0, sqlCount: 8), $previous);
        self::assertNotNull($faster);
        self::assertNotNull($slower);
        self::assertSame(-20.0, $faster['duration_percent']);
        self::assertSame(20.0, $slower['duration_percent']);
        self::assertSame(-2.0, $faster['sql_difference']);
        self::assertSame(2.0, $slower['sql_difference']);
        $unchanged = $this->ui()->compare($this->profile(12, 100, duration: 10.0, sqlCount: 6), $previous);
        self::assertNotNull($unchanged);
        self::assertSame(0.0, $unchanged['duration_percent']);
        self::assertSame(0.0, $unchanged['sql_difference']);
        self::assertSame(2.0, $unchanged['average_sql_ms']);
    }

    public function testZeroReferenceAverageNeverProducesAPercentage(): void
    {
        $previous = [];
        for ($index = 1; $index <= 5; ++$index) {
            $previous[] = $this->profile($index, $index, duration: 0.0, sqlCount: 0, sqlMs: 0.0);
        }

        $increase = $this->ui()->compare($this->profile(10, 100, duration: 1.0, sqlCount: 3, sqlMs: 0.5), $previous);
        self::assertNotNull($increase);
        self::assertNull($increase['duration_percent']);
        self::assertSame(0.0, $increase['average_duration_ms']);
        self::assertSame(3.0, $increase['sql_difference']);
        self::assertSame(0.0, $increase['average_sql_ms']);

        $zero = $this->ui()->compare($this->profile(11, 100, duration: 0.0, sqlCount: 0), $previous);
        self::assertNotNull($zero);
        self::assertNull($zero['duration_percent']);
        self::assertSame(0.0, $zero['sql_difference']);
        self::assertSame('0', $this->ui()->queryAverage(0.0));
        self::assertSame('<0.01', $this->ui()->queryAverage(0.001));
    }

    public function testInvalidOrMissingMetricsCannotEnterTheAvailableHistory(): void
    {
        $valid = $this->profile(1, 1)->toArray();
        foreach (['duration_ms' => 'not measured', 'sql_count' => null, 'sql_ms' => 'not measured'] as $name => $invalid) {
            $corrupt = $valid;
            $corrupt[$name] = $invalid;
            self::assertNull(Profile::decode(json_encode($corrupt, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
        }
        $missing = $valid;
        unset($missing['duration_ms']);
        self::assertNull(Profile::decode(json_encode($missing, JSON_THROW_ON_ERROR)));
        self::assertNull($this->ui()->compare($this->profile(10, 100), [$this->profile(2, 2)]));
    }

    private function ui(): ProfilerUi
    {
        return new ProfilerUi(new ProfilerTimeline());
    }

    private function withRoute(Profile $profile, ?string $route): Profile
    {
        $data = $profile->toArray();
        $data['route'] = $route;
        return new Profile($data);
    }

    private function profile(
        int $id,
        int $createdAt,
        ?string $route = 'fixture.page',
        string $method = 'GET',
        ?int $status = 200,
        float $duration = 10.0,
        int $sqlCount = 0,
        float $sqlMs = 0.0,
    ): Profile {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->completed(new HttpMetrics($duration, 1024, $sqlCount, $sqlMs));
        $data = $context->snapshot($method, $status, null)->toArray();
        $data['id'] = str_pad(dechex($id), 32, '0', STR_PAD_LEFT);
        $data['created_at'] = $createdAt;
        $data['route'] = $route;
        $data['path'] = '/page/{id}';
        return new Profile($data);
    }
}
