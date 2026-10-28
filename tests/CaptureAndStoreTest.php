<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfileContext;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\ProfilerUi;
use Lemonade\Profiler\ProfileStore;
use Lemonade\Profiler\SqlSanitizer;
use Lemonade\Profiler\Tests\Support\TestApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CaptureAndStoreTest extends TestCase
{
    public function testCaptureCountsEarlyAndLateSqlAndBoundsDetails(): void
    {
        $context = new ProfileContext(new ProfilerConfig(maxQueries: 1, maxRenderings: 1), new SqlSanitizer(), hrtime(true));
        $context->queryCompleted("SELECT 'private'", 110.0, true);
        $context->queryCompleted('SELECT after', 3.0, true);
        $first = $context->beginRendering('page');
        $context->endRendering($first, true);
        self::assertNull($context->beginRendering('ignored'));
        $metrics = new HttpMetrics(120.0, 1024, 3, 115.0);
        $context->completed($metrics);
        $context->queryCompleted('SELECT ignored', 20.0, true);
        $profile = $context->snapshot('GET', 200, null);
        self::assertSame($metrics->sqlCount(), $profile->data['sql_count']);
        self::assertSame($metrics->sqlMs(), $profile->data['sql_ms']);
        self::assertSame($metrics->durationMs(), $profile->data['duration_ms']);
        self::assertSame($metrics->phpPeakMemoryBytes(), $profile->data['peak_bytes']);
        self::assertCount(1, $profile->data['queries']);
        self::assertTrue($profile->data['queries'][0]['slow']);
        self::assertSame('SELECT ?', $profile->data['queries'][0]['sql']);
        self::assertCount(1, $profile->data['renderings']);
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
            self::assertSame([$third->data['id'], $second->data['id']], array_map(static fn(Profile $profile): string => $profile->data['id'], $store->history()));
            self::assertNull($store->find($expired->data['id']));
            self::assertNull($store->find($first->data['id']));
            self::assertNull($store->find('../../etc/passwd'));
            self::assertFileExists($directory . '/unrelated.json');
            self::assertSame(0600, fileperms($directory . '/' . $third->data['id'] . '.json') & 0777);
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
            self::assertSame([], $store->history());
            self::assertSame('private', file_get_contents($outside));
            self::assertNull(Profile::decode('{"id":"bad"}'));
        } finally {
            $app->close();
        }
    }

    public function testTimelineKeepsOverlapAndDoesNotInventAnEndForFailedAction(): void
    {
        $profile = $this->sample();
        $data = $profile->data;
        $data['marks'] = [
            ['name' => 'controller_action_started', 'elapsed_ms' => 3.0],
        ];
        $data['renderings'] = [
            ['order' => 1, 'template' => 'page', 'start_ms' => 5.0, 'elapsed_ms' => 2.0, 'completed' => false],
        ];
        $timeline = (new ProfilerUi())->timeline(new Profile($data));
        self::assertNull($timeline[0]['duration']);
        self::assertSame(3.0, $timeline[0]['start']);
        self::assertSame(5.0, $timeline[1]['start']);
        self::assertSame(2.0, $timeline[1]['duration']);
    }

    public function testPreviouslyStoredHistoryRemainsReadable(): void
    {
        $data = $this->sample()->data;
        unset($data['connection_ms']);
        $data['benchmark_ms'] = 0.5;
        $data['early_sql_count'] = 0;
        $data['marks'] = [['name' => 'controller_action_start', 'elapsed_ms' => 0.2]];
        $profile = Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        self::assertNotNull($profile);
        self::assertSame($data['id'], $profile->data['id']);
        self::assertSame($data['duration_ms'], $profile->data['duration_ms']);
        self::assertNull($profile->data['connection_ms']);
        self::assertSame('controller_action_started', $profile->data['marks'][0]['name']);
    }

    private function sample(int $ageSeconds = 0): Profile
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->data;
        $data['created_at'] += $ageSeconds * 1000000;
        return new Profile($data);
    }
}
