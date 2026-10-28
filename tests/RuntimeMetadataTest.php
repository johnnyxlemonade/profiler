<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Composer\InstalledVersions;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\Metadata\ComposerMetadata;
use Lemonade\Profiler\Capture\Metadata\ServerMetadata;
use Lemonade\Profiler\Capture\Metadata\SessionMetadata;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RuntimeMetadataTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeSessionObservationDoesNotStartCloseOrExposeSession(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame('inactive', SessionMetadata::capture()['values']['status']);
        self::assertNull(SessionMetadata::capture()['values']['items']);
        self::assertSame(PHP_SESSION_NONE, session_status());
        session_start(['use_cookies' => false, 'cache_limiter' => '']);
        $_SESSION = ['secret-key' => 'secret-value'];
        $id = session_id();
        self::assertIsString($id);
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->captureSession();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame($id, session_id());
        self::assertSame(['secret-key' => 'secret-value'], $_SESSION);
        session_write_close();
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $profile = $context->snapshot('GET', 200, null);
        $diagnostics = $profile->toArray()['diagnostics'] ?? [];
        self::assertNotEmpty($diagnostics);
        self::assertSame('active', $diagnostics['session']['values']['status']);
        self::assertSame('1', $diagnostics['session']['values']['items']);
        $json = json_encode($profile->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        self::assertStringNotContainsString($id, $json);
        self::assertStringNotContainsString('secret-', $json);
        self::assertNotNull(Profile::decode($json));
        self::assertSame('inactive', SessionMetadata::capture()['values']['status']);
    }

    public function testFrameworkSessionIsObservedWithoutReadingOrChangingIt(): void
    {
        $session = $this->createMock(\Lemonade\Framework\Session\Contract\SessionInterface::class);
        $session->expects(self::once())->method('started')->willReturn(true);
        foreach (['start', 'has', 'get', 'set', 'remove', 'clear', 'destroy', 'regenerate'] as $method) {
            $session->expects(self::never())->method($method);
        }
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->observeSession($session);
        $context->captureSession();
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $diagnostics = $context->snapshot('GET', 200, null)->toArray()['diagnostics'] ?? [];
        self::assertSame('active', $diagnostics['session']['values']['status']);
        self::assertNull($diagnostics['session']['values']['items']);
    }

    public function testRuntimeUsesBoundedInstalledMetadataWithoutServerSecrets(): void
    {
        $server = ServerMetadata::capture('dev', 'nginx/1.26.3 secret-host /private/path');
        self::assertSame('nginx/1.26.3', $server['values']['web_server']);
        self::assertStringNotContainsString('secret-host', json_encode($server, JSON_THROW_ON_ERROR));
        self::assertSame(PHP_VERSION, $server['values']['php_version']);
        self::assertSame(PHP_SAPI, $server['values']['php_sapi']);
        self::assertNotEmpty($server['entries']);
        $composer = ComposerMetadata::capture();
        self::assertSame(InstalledVersions::getRootPackage()['name'], $composer['values']['application']);
        self::assertLessThanOrEqual(500, count($composer['entries']));
        foreach ($composer['entries'] as $entry) {
            self::assertSame(InstalledVersions::getPrettyVersion($entry['name']), $entry['version']);
            self::assertSame(['name', 'version', 'dependency'], array_keys($entry));
        }
        self::assertStringNotContainsString(dirname(__DIR__), json_encode([$server, $composer], JSON_THROW_ON_ERROR));
    }

    public function testComposerClassifiesOnlyAgainstTheVerifiedApplicationRoot(): void
    {
        $classified = ComposerMetadata::capture(dirname(__DIR__));
        self::assertSame('available', $classified['values']['dependency_classification']);
        $byName = array_column($classified['entries'], 'dependency', 'name');
        self::assertSame('direct', $byName['johnnyxlemonade/framework']);
        self::assertSame('development', $byName['phpunit/phpunit']);
        self::assertSame('transitive', $byName['psr/container']);
        self::assertArrayNotHasKey('composer_version', $classified['values']);

        $unverified = ComposerMetadata::capture(sys_get_temp_dir());
        self::assertNull($unverified['values']['dependency_classification']);
        foreach ($unverified['entries'] as $entry) {
            self::assertNull($entry['dependency']);
        }
    }

    public function testMissingAndUnsafeServerValuesRemainUnavailable(): void
    {
        $server = ServerMetadata::capture(str_repeat('x', 161), 'internal-host /private/path');
        self::assertNull($server['values']['environment']);
        self::assertNull($server['values']['web_server']);
        self::assertLessThanOrEqual(256, count($server['entries']));
        self::assertNull(ServerMetadata::capture("dev\nSECRET=value")['values']['environment']);
        self::assertNull(ServerMetadata::capture('/private/path')['values']['environment']);

        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $toolbar = (new ProfilerUi(new ProfilerTimeline()))->toolbarData($context->snapshot('GET', 200, null));
        self::assertSame([
            'php_version', 'php_sapi', 'memory_limit', 'max_execution_time', 'timezone',
            'web_server', 'os', 'environment', 'framework_version',
        ], array_keys($toolbar['server']));
        self::assertSame(array_fill(0, 9, null), array_values($toolbar['server']));
    }

    public function testStoredDiagnosticsRejectInvalidKeysValuesAndLimits(): void
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        $invalidSections = [
            ['values' => ['PHP version' => '8.3'], 'entries' => []],
            ['values' => ['php_version' => ['nested']], 'entries' => []],
            ['values' => ['php_version' => str_repeat('x', 161)], 'entries' => []],
            ['values' => array_fill_keys(array_map(static fn(int $i): string => 'key_' . $i, range(0, 20)), 'value'), 'entries' => []],
            ['values' => [], 'entries' => array_fill(0, 501, ['name' => 'package', 'version' => null, 'dependency' => null])],
            ['values' => [], 'entries' => [['name' => 'package', 'version' => null, 'dependency' => null, 'path' => '/private/path']]],
        ];
        foreach ($invalidSections as $section) {
            $data['diagnostics'] = ['server' => $section];
            self::assertNull(Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
        }
    }

    public function testInvalidDiagnosticsAreRejected(): void
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        self::assertNotNull(Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
        $data['diagnostics'] = ['session' => ['values' => [], 'entries' => [['name' => 'bad']]]];
        self::assertNull(Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }
}
