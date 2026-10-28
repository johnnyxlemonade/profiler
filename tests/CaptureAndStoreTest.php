<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use InvalidArgumentException;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Storage\ProfileStore;
use Lemonade\Profiler\Tests\Support\TestApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CaptureAndStoreTest extends TestCase
{
    public function testCaptureCountsEarlyAndLateSqlAndBoundsDetails(): void
    {
        $context = new ProfileContext(config: new ProfilerConfig(maxQueries: 1, maxRenderings: 1), sanitizer: new SqlSanitizer(), startedNs: hrtime(true));
        $context->queryCompleted("SELECT 'private'", 110.0, true);
        $context->queryCompleted('SELECT after', 3.0, true);
        $first = $context->beginRendering('page');
        $context->endRendering($first, true);
        self::assertNull($context->beginRendering('ignored'));
        $metrics = new HttpMetrics(durationMs: 120.0, phpPeakMemoryBytes: 1024, sqlCount: 3, sqlMs: 115.0);
        $context->completed($metrics);
        $context->queryCompleted('SELECT ignored', 20.0, true);
        $profile = $context->snapshot('GET', 200, null);
        self::assertSame($metrics->sqlCount(), $profile->toArray()['sql_count']);
        self::assertSame($metrics->sqlMs(), $profile->toArray()['sql_ms']);
        self::assertSame($metrics->durationMs(), $profile->toArray()['duration_ms']);
        self::assertSame($metrics->phpPeakMemoryBytes(), $profile->toArray()['peak_bytes']);
        self::assertCount(1, $profile->toArray()['queries']);
        self::assertTrue($profile->toArray()['queries'][0]['slow']);
        self::assertSame('SELECT ?', $profile->toArray()['queries'][0]['sql']);
        self::assertCount(1, $profile->toArray()['renderings']);
    }

    public function testHistoryPrunesByAgeAndCountAndNeverDeletesUnrelatedFiles(): void
    {
        $app = new TestApplication(config: new ProfilerConfig(maxProfiles: 2));
        try {
            $app->kernel->bootstrap();
            $store = $app->container->get(ProfileStore::class);
            $first = $this->sample(-10);
            $second = $this->sample(-5);
            $third = $this->sample();
            $expired = $this->sample(-3601);
            foreach ([$first, $second, $third, $expired] as $profile) {
                $store->save($profile);
            }
            $directory = $app->root . '/storage/writable/profiler';
            file_put_contents($directory . '/unrelated.json', 'keep');
            self::assertSame([$third->id(), $second->id()], array_map(static fn(Profile $profile): string => $profile->id(), $store->history()));
            self::assertNull($store->find($expired->id()));
            self::assertNull($store->find($first->id()));
            self::assertNull($store->find('../../etc/passwd'));
            self::assertFileExists($directory . '/unrelated.json');
            self::assertSame(0600, fileperms($directory . '/' . $third->id() . '.json') & 0777);
            self::assertSame(0700, fileperms($directory) & 0777);
        } finally {
            $app->close();
        }
    }

    public function testOversizedProfileIsNotPublished(): void
    {
        $app = new TestApplication(config: new ProfilerConfig(maxProfileBytes: 50));
        try {
            $app->kernel->bootstrap();
            $store = $app->container->get(ProfileStore::class);
            try {
                $store->save($this->sample());
                self::fail('Oversized profile must be rejected');
            } catch (RuntimeException) {
                self::assertSame([], $store->history());
            }
        } finally {
            $app->close();
        }
    }

    public function testMalformedProfileIsRejectedAndSymlinkTargetIsUntouched(): void
    {
        $app = new TestApplication();
        try {
            $app->kernel->bootstrap();
            $store = $app->container->get(ProfileStore::class);
            $store->history();
            $directory = $app->root . '/storage/writable/profiler';
            $outside = $app->root . '/outside.json';
            file_put_contents($outside, 'private');
            symlink($outside, $directory . '/' . str_repeat('a', 32) . '.json');
            file_put_contents($directory . '/' . str_repeat('b', 32) . '.json', '{invalid');
            $oversized = $directory . '/' . str_repeat('c', 32) . '.json';
            file_put_contents($oversized, str_repeat('x', (new ProfilerConfig())->maxProfileBytes() + 1));
            $corrupt = $this->sample()->toArray();
            $corrupt['sql_count'] = 'invalid';
            file_put_contents($directory . '/' . $corrupt['id'] . '.json', json_encode($corrupt, JSON_THROW_ON_ERROR));
            self::assertSame([], $store->history());
            self::assertNull($store->find($corrupt['id']));
            self::assertSame('private', file_get_contents($outside));
            self::assertFileDoesNotExist($oversized);
            self::assertNull(Profile::decode('{"id":"bad"}'));
        } finally {
            $app->close();
        }
    }

    public function testTimelineKeepsOverlapAndDoesNotInventAnEndForFailedAction(): void
    {
        $profile = $this->sample();
        $data = $profile->toArray();
        $data['marks'] = [
            ['name' => 'controller_action_started', 'elapsed_ms' => 3.0],
        ];
        $data['renderings'] = [
            ['order' => 1, 'template' => 'page', 'start_ms' => 5.0, 'elapsed_ms' => 2.0, 'completed' => false],
        ];
        $timeline = (new ProfilerTimeline())->intervals(new Profile($data));
        self::assertSame('controller_action_started', $timeline[0]['phase']);
        self::assertSame('render', $timeline[1]['phase']);
        self::assertSame('page', $timeline[1]['template'] ?? null);
        self::assertNull($timeline[0]['duration']);
        self::assertSame(3.0, $timeline[0]['start']);
        self::assertSame(5.0, $timeline[1]['start']);
        self::assertSame(2.0, $timeline[1]['duration']);
    }

    public function testCurrentSnapshotRoundTripsWithoutLegacyMigration(): void
    {
        $data = $this->sample()->toArray();
        $profile = Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        self::assertNotNull($profile);
        self::assertSame($data, $profile->toArray());

        $copy = $profile->toArray();
        $copy['id'] = str_repeat('f', 32);
        self::assertSame($data['id'], $profile->id());

        unset($data['connection_ms'], $data['diagnostics']);
        $data['benchmark_ms'] = 0.5;
        $data['early_sql_count'] = 0;
        self::assertNull(Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }

    public function testCorruptSnapshotTypesAndLimitsAreRejected(): void
    {
        $valid = $this->sample()->toArray();
        self::assertNull(Profile::decode('{invalid'));

        $wrongType = $valid;
        $wrongType['duration_ms'] = '1.0';
        self::assertNull(Profile::decode(json_encode($wrongType, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));

        $incomplete = $valid;
        unset($incomplete['diagnostics']);
        self::assertNull(Profile::decode(json_encode($incomplete, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));

        $oversized = $valid;
        $oversized['diagnostics']['server'] = ['values' => ['long' => str_repeat('x', 161)], 'entries' => []];
        self::assertNull(Profile::decode(json_encode($oversized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));

        $unknownField = $valid;
        $unknownField['raw_uri'] = '/secret';
        $this->expectException(InvalidArgumentException::class);
        new Profile($unknownField);
    }

    private function sample(int $ageSeconds = 0): Profile
    {
        $context = new ProfileContext(config: new ProfilerConfig(), sanitizer: new SqlSanitizer(), startedNs: hrtime(true));
        $context->completed(new HttpMetrics(durationMs: 1.0, phpPeakMemoryBytes: 1024, sqlCount: 0, sqlMs: 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        $data['created_at'] += $ageSeconds * 1000000;
        return new Profile($data);
    }
}
