<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Core\Context\Environment;
use Lemonade\Framework\Observability\Http\Internal\HttpInstrumentation;
use Lemonade\Profiler\Http\ProfilerMiddleware;
use Lemonade\Profiler\Http\ProfilerRequestHandler;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Storage\ProfileStore;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class KernelIntegrationTest extends TestCase
{
    private TestApplication $app;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->app = new TestApplication();
    }

    protected function tearDown(): void
    {
        $this->app->close();
    }

    public function testRealKernelCapturesIsolatedRequestsWithoutSensitiveData(): void
    {
        $request = (new ServerRequest('GET', '/page/secret-route?token=secret-query', [
            'Authorization' => 'Bearer secret-auth',
            'Cookie' => 'session=secret-cookie',
        ], 'secret-body'))->withParsedBody(['password' => 'secret-form']);
        $first = $this->app->kernel->run($request);
        $second = $this->app->kernel->run(new ServerRequest('GET', '/page/second'));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertNotSame($first->getHeaderLine('X-Lemonade-Profiler'), $second->getHeaderLine('X-Lemonade-Profiler'));
        $profiles = $this->app->container->get(ProfileStore::class)->history();
        self::assertCount(2, $profiles);
        foreach ($profiles as $profile) {
            $diagnostics = $profile->toArray()['diagnostics'] ?? [];
            self::assertNotEmpty($diagnostics);
            self::assertArrayHasKey('session', $diagnostics);
            self::assertSame(PHP_VERSION, $diagnostics['server']['values']['php_version']);
            self::assertNotEmpty($diagnostics['composer']['entries']);
            self::assertSame(2, $profile->toArray()['sql_count']);
            self::assertCount(2, $profile->toArray()['queries']);
            self::assertCount(1, $profile->toArray()['renderings']);
            self::assertSame('page', $profile->toArray()['renderings'][0]['template']);
            self::assertTrue($profile->toArray()['renderings'][0]['completed']);
            self::assertSame('fixture.page', $profile->toArray()['route']);
            self::assertSame('/page/{id}', $profile->toArray()['path']);
            self::assertGreaterThan(0, $profile->toArray()['duration_ms']);
            self::assertGreaterThan(0, $profile->toArray()['peak_bytes']);
            $json = file_get_contents($this->app->root . '/storage/writable/profiler/' . $profile->id() . '.json');
            self::assertIsString($json);
            self::assertStringNotContainsString('secret-', $json);
            self::assertStringNotContainsString('bindings', $json);
        }
        self::assertNull($this->app->container->get(HttpInstrumentation::class)->observer());
    }

    public function testToolbarMetadataUsesStoredTotalsAndContainsNoRequestSecrets(): void
    {
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/secret-id?token=secret-token'));
        $profile = $this->app->container->get(ProfileStore::class)->history()[0];
        $json = rawurldecode($response->getHeaderLine('X-Lemonade-Profiler-Metrics'));
        $metadata = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame(['id', 'method', 'status', 'route', 'path', 'duration_ms', 'peak_bytes', 'sql_count', 'sql_ms'], array_keys($metadata));
        foreach ($metadata as $key => $value) {
            self::assertEquals($profile->toArray()[$key], $value);
        }
        self::assertStringNotContainsString('secret-', $json);
        self::assertFalse($response->hasHeader('X-Lemonade-Time-Ms'));
        $toolbar = $this->app->container->get(ProfilerUi::class)->toolbarData($profile);
        self::assertSame($profile->toArray()['diagnostics']['server']['values']['php_version'] ?? null, $toolbar['server']['php_version']);
        self::assertSame($profile->toArray()['diagnostics']['server']['values']['web_server'] ?? null, $toolbar['server']['web_server']);
        self::assertCount(9, $toolbar['server']);
        self::assertArrayNotHasKey('diagnostics', $toolbar);
        $durations = array_column($profile->toArray()['queries'], 'elapsed_ms');
        self::assertNotEmpty($durations);
        self::assertSame(max(0.0, ...$durations), $toolbar['longest']['elapsed_ms']);
        self::assertStringNotContainsString('secret-', json_encode($toolbar, JSON_THROW_ON_ERROR));
    }

    public function testHeadersAndProfilerShareTotalsIncludingBootstrapAndMiddlewareUnwind(): void
    {
        $this->app->kernel->framework()->config(
            \Lemonade\Framework\Observability\Http\Config\HttpDiagnosticsConfigDefinition::create()->enabled(),
        );
        $this->app->container->scoped(ProfilerLateSqlMiddleware::class, ProfilerLateSqlMiddleware::class);
        $this->app->kernel->framework()->middleware(static function (\Lemonade\Framework\Http\Middleware\MiddlewareStack $stack): void {
            $stack->prepend(ProfilerLateSqlMiddleware::class);
        });
        file_put_contents($this->app->root . '/app/Config/Providers.yaml', <<<'PROVIDERS'
module: providers
config:
  providers:
    - Lemonade\Profiler\ProfilerServiceProvider
    - Lemonade\Profiler\Tests\ProfilerBootstrapSqlProvider
PROVIDERS);
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/shared'));
        self::assertSame(200, $response->getStatusCode());
        $profiles = $this->app->container->get(ProfileStore::class)->history();
        self::assertCount(1, $profiles);
        $data = $profiles[0]->toArray();
        self::assertSame(4, $data['sql_count']);
        self::assertCount(4, $data['queries']);
        self::assertSame(array_sum(array_column($data['queries'], 'elapsed_ms')), $data['sql_ms']);
        self::assertSame(number_format($data['duration_ms'], 3, '.', ''), $response->getHeaderLine('X-Lemonade-Time-Ms'));
        self::assertSame(number_format($data['sql_ms'], 3, '.', ''), $response->getHeaderLine('X-Lemonade-SQL-Time-Ms'));
        self::assertSame((string) $data['sql_count'], $response->getHeaderLine('X-Lemonade-SQL-Count'));
        self::assertSame((string) $data['peak_bytes'], $response->getHeaderLine('X-Lemonade-PHP-Peak-Bytes'));
        $marks = array_column($data['marks'], 'name');
        self::assertContains('request_started', $marks);
        self::assertNotContains('configuration_loaded', $marks);
        self::assertContains('providers_booted', $marks);
        self::assertContains('controller_action_finished', $marks);
        self::assertGreaterThan(0.0, $data['connection_ms']);
    }

    public function testHealthBeforeBootstrapAndMissingRouteAreProfiled(): void
    {
        $health = $this->app->kernel->run(new ServerRequest('GET', '/api/framework/health'));
        self::assertSame(200, $health->getStatusCode());
        self::assertTrue($health->hasHeader('X-Lemonade-Profiler'));
        $missing = $this->app->kernel->run(new ServerRequest('GET', '/missing'));
        self::assertSame(404, $missing->getStatusCode());
        $profiles = $this->app->container->get(ProfileStore::class)->history();
        self::assertCount(2, $profiles);
        $snapshots = array_map(static fn(Profile $profile): array => $profile->toArray(), $profiles);
        self::assertSame([404, 200], array_column($snapshots, 'status'));
        self::assertSame([0, 0], array_column($snapshots, 'sql_count'));
        self::assertNotContains('bootstrap_started', array_column($profiles[1]->toArray()['marks'], 'name'));
    }

    public function testProfilerResourcesDoNotCreateProfiles(): void
    {
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/1'));
        $detail = $response->getHeaderLine('X-Lemonade-Profiler');
        $resources = [
            '/_lemonade/profiler' => 'text/html; charset=utf-8',
            $detail => 'text/html; charset=utf-8',
            '/_lemonade/profiler/assets/profiler.css' => 'text/css; charset=utf-8',
            '/_lemonade/profiler/assets/toolbar.css' => 'text/css; charset=utf-8',
            '/_lemonade/profiler/assets/symbol.svg' => 'image/svg+xml',
        ];
        foreach (['toolbar.js', 'profiler-toolbar.js', 'request-monitor.js', 'icons.js', 'format.js', 'history-filters.js'] as $name) {
            $resources['/_lemonade/profiler/assets/' . $name] = 'text/javascript; charset=utf-8';
        }
        foreach ($resources as $path => $type) {
            $resource = $this->app->kernel->run(new ServerRequest('GET', $path));
            self::assertSame(200, $resource->getStatusCode());
            self::assertSame($type, $resource->getHeaderLine('Content-Type'));
            self::assertSame('no-store', $resource->getHeaderLine('Cache-Control'));
            self::assertSame('no-referrer', $resource->getHeaderLine('Referrer-Policy'));
            self::assertSame('nosniff', $resource->getHeaderLine('X-Content-Type-Options'));
            self::assertNotSame('', $resource->getHeaderLine('Content-Security-Policy'));
            self::assertStringContainsString("img-src 'self'", $resource->getHeaderLine('Content-Security-Policy'));
            if ($path === '/_lemonade/profiler') {
                self::assertStringContainsString("script-src 'self'", $resource->getHeaderLine('Content-Security-Policy'));
            }
            if ($path === $detail) {
                self::assertStringNotContainsString('script-src', $resource->getHeaderLine('Content-Security-Policy'));
            }
            self::assertFalse($resource->hasHeader('X-Lemonade-Profiler'));
            if (str_contains($path, '/assets/')) {
                $name = basename($path);
                $directory = str_ends_with($name, '.css') ? 'css' : (str_ends_with($name, '.svg') ? 'brand' : 'js');
                self::assertSame(
                    file_get_contents(dirname(__DIR__) . '/resources/assets/' . $directory . '/' . $name),
                    (string) $resource->getBody(),
                );
            }
        }
        $head = $this->app->kernel->run(new ServerRequest('HEAD', '/_lemonade/profiler/assets/toolbar.js'));
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
        self::assertSame('text/javascript; charset=utf-8', $head->getHeaderLine('Content-Type'));
        self::assertCount(1, $this->app->container->get(ProfileStore::class)->history());
        $methodNotAllowed = $this->app->kernel->run(new ServerRequest('POST', '/_lemonade/profiler'));
        self::assertSame(405, $methodNotAllowed->getStatusCode());
        self::assertSame('GET, HEAD', $methodNotAllowed->getHeaderLine('Allow'));
        self::assertSame(404, $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler/request/invalid'))->getStatusCode());
        foreach (['unknown.js', '../i18n/en.json', '%2e%2e/i18n/en.json', 'js/toolbar.js', 'toolbar.js/extra', 'toolbar.js%00'] as $name) {
            $resource = $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler/assets/' . $name));
            self::assertSame(404, $resource->getStatusCode());
        }
    }

    public function testPresentationFailureIsNotReportedAsStorageFailure(): void
    {
        $this->app->kernel->bootstrap();
        $failure = new RuntimeException('Presentation failed');
        $calls = 0;
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams->method('createStream')->willReturnCallback(static function (string $content) use (&$calls, $failure): StreamInterface {
            if (++$calls === 1) {
                throw $failure;
            }

            return (new Psr17Factory())->createStream($content);
        });
        $handler = new ProfilerRequestHandler(
            config: $this->app->container->get(ProfilerConfig::class),
            store: $this->app->container->get(ProfileStore::class),
            ui: $this->app->container->get(ProfilerUi::class),
            assets: $this->app->container->get(\Lemonade\Profiler\Presentation\ProfilerAssets::class),
            responses: $this->app->container->get(ResponseFactoryInterface::class),
            streams: $streams,
        );
        $middleware = new ProfilerMiddleware(
            config: $this->app->container->get(ProfilerConfig::class),
            profiler: static fn(): ProfilerRequestHandler => $handler,
        );

        $response = $middleware->process(
            new ServerRequest('GET', '/_lemonade/profiler'),
            $this->createMock(RequestHandlerInterface::class),
        );
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('Profiler is unavailable', (string) $response->getBody());
        self::assertSame(1, $calls);
    }

    public function testHandledExceptionKeepsItsStatusAndDoesNotLeakIntoNextRequest(): void
    {
        $error = $this->app->kernel->run(new ServerRequest('GET', '/error'));
        self::assertSame(500, $error->getStatusCode());
        $this->app->kernel->run(new ServerRequest('GET', '/page/clean'));
        $profiles = $this->app->container->get(ProfileStore::class)->history();
        $snapshots = array_map(static fn(Profile $profile): array => $profile->toArray(), $profiles);
        self::assertSame([2, 1], array_column($snapshots, 'sql_count'));
        self::assertStringNotContainsString('secret-exception', json_encode($snapshots, JSON_THROW_ON_ERROR));
    }

    public function testEnabledProfilerWorksInDevelopmentAndProductionWithEitherDebugMode(): void
    {
        foreach ([Environment::Development, Environment::Production] as $environment) {
            foreach ([false, true] as $debug) {
                foreach ([false, true] as $earlyObserver) {
                    $application = new TestApplication($environment, debug: $debug, earlyObserver: $earlyObserver);
                    try {
                        $response = $application->kernel->run(new ServerRequest('GET', '/page/1'));
                        self::assertSame(200, $response->getStatusCode());
                        self::assertTrue($application->container->isBound(ProfilerMiddleware::class));
                        self::assertTrue($response->hasHeader('X-Lemonade-Profiler'));
                        self::assertTrue($response->hasHeader('X-Lemonade-Profiler-Metrics'));
                        self::assertStringContainsString('data-lemonade-profile=', (string) $response->getBody());
                        self::assertCount(1, $application->container->get(ProfileStore::class)->history());
                        self::assertSame(200, $application->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
                        self::assertSame(200, $application->kernel->run(new ServerRequest('GET', $response->getHeaderLine('X-Lemonade-Profiler')))->getStatusCode());
                        self::assertNull($application->container->get(HttpInstrumentation::class)->observer());
                    } finally {
                        $application->close();
                    }
                }
            }
        }
    }

    public function testYamlProviderAloneCapturesAndServesHistory(): void
    {
        $application = new TestApplication(earlyObserver: false);
        try {
            $response = $application->kernel->run(new ServerRequest('GET', '/page/1'));
            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($response->hasHeader('X-Lemonade-Profiler'));
            self::assertNull($application->container->get(HttpInstrumentation::class)->observer());
            self::assertCount(1, $application->container->get(ProfileStore::class)->history());
            self::assertSame(200, $application->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
        } finally {
            $application->close();
        }
    }

    public function testOmittingYamlProviderDisablesProfiler(): void
    {
        file_put_contents($this->app->root . '/app/Config/Providers.yaml', "module: providers\nconfig:\n  providers: []\n");
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Lemonade-Profiler'));
        self::assertFalse($this->app->container->isBound(ProfilerMiddleware::class));
        self::assertDirectoryDoesNotExist($this->app->root . '/storage/writable/profiler');
        self::assertSame(404, $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
    }

    public function testMissingYamlProviderIsAConfigurationErrorEvenForHealth(): void
    {
        file_put_contents($this->app->root . '/app/Config/Providers.yaml', <<<'YAML'
module: providers
config:
  providers:
    - Missing\OptionalPackage\ServiceProvider
YAML);
        foreach (['/page/1', '/api/framework/health'] as $path) {
            self::assertSame(500, $this->app->kernel->run(new ServerRequest('GET', $path))->getStatusCode());
        }
        self::assertNull($this->app->container->get(HttpInstrumentation::class)->observer());
    }

    public function testExplicitEarlyFactoryStoresBootstrapFailureBeforeFilesystemRegistration(): void
    {
        $application = new TestApplication(earlyObserver: true);
        try {
            file_put_contents($application->root . '/app/Config/Providers.yaml', <<<'YAML'
module: providers
config:
  providers:
    - Missing\OptionalPackage\ServiceProvider
YAML);

            $response = $application->kernel->run(new ServerRequest('GET', '/page/1'));
            self::assertSame(500, $response->getStatusCode());
            self::assertTrue($response->hasHeader('X-Lemonade-Profiler'));
            self::assertTrue($response->hasHeader('X-Lemonade-Profiler-Metrics'));
            self::assertFalse($application->container->isBound(ProfileStore::class));
            $profiles = glob($application->root . '/storage/writable/profiler/*.json');
            self::assertIsArray($profiles);
            self::assertCount(1, $profiles);
            $json = file_get_contents($profiles[0]);
            self::assertIsString($json);
            self::assertSame(500, json_decode($json, true, 32, JSON_THROW_ON_ERROR)['status']);
            self::assertNull($application->container->get(HttpInstrumentation::class)->observer());
        } finally {
            $application->close();
        }
    }

    public function testExplicitEarlyFactoryStillCapturesConfigurationExactlyOnce(): void
    {
        $application = new TestApplication(earlyObserver: true);
        try {
            self::assertSame(200, $application->kernel->run(new ServerRequest('GET', '/page/1'))->getStatusCode());
            $profiles = $application->container->get(ProfileStore::class)->history();
            self::assertCount(1, $profiles);
            self::assertSame(2, $profiles[0]->toArray()['sql_count']);
            self::assertContains('configuration_loaded', array_column($profiles[0]->toArray()['marks'], 'name'));
        } finally {
            $application->close();
        }
    }

    public function testDisabledProfilerHasNoCaptureHeadersToolbarOrEndpointsInAnyEnvironment(): void
    {
        foreach ([Environment::Development, Environment::Production] as $environment) {
            foreach ([false, true] as $debug) {
                foreach ([false, true] as $earlyObserver) {
                    $application = new TestApplication($environment, new ProfilerConfig(enabled: false), $debug, $earlyObserver);
                    try {
                        $response = $application->kernel->run(new ServerRequest('GET', '/page/1'));
                        self::assertSame(200, $response->getStatusCode());
                        self::assertFalse($application->container->isBound(ProfilerMiddleware::class));
                        self::assertFalse($response->hasHeader('X-Lemonade-Profiler'));
                        self::assertFalse($response->hasHeader('X-Lemonade-Profiler-Metrics'));
                        self::assertStringNotContainsString('data-lemonade-profile=', (string) $response->getBody());
                        self::assertFalse($application->container->isBound(ProfileStore::class));
                        self::assertDirectoryDoesNotExist($application->root . '/storage/writable/profiler');
                        self::assertSame(404, $application->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
                        self::assertNull($application->container->get(HttpInstrumentation::class)->observer());
                    } finally {
                        $application->close();
                    }
                }
            }
        }
    }

    public function testProfilerDependencyFailurePreservesApplicationResponses(): void
    {
        file_put_contents($this->app->root . '/app/Config/Providers.yaml', <<<'PROVIDERS'
module: providers
config:
  providers:
    - Lemonade\Profiler\ProfilerServiceProvider
    - Lemonade\Profiler\Tests\ProfilerUnavailableStoreProvider
PROVIDERS);
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Lemonade-Profiler'));
        self::assertSame(503, $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
        self::assertNull($this->app->container->get(HttpInstrumentation::class)->observer());
    }

    public function testStorageFailurePreservesTheApplicationResponse(): void
    {
        mkdir($this->app->root . '/storage/writable', 0700, true);
        file_put_contents($this->app->root . '/storage/writable/profiler', 'occupied');
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Lemonade-Profiler'));
        self::assertSame(503, $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
    }
}

final readonly class ProfilerLateSqlMiddleware implements \Psr\Http\Server\MiddlewareInterface
{
    public function __construct(private \Lemonade\Framework\Database\Connection\ConnectionInterface $connection) {}

    public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
    {
        $response = $handler->handle($request);
        $this->connection->select('SELECT 42');
        return $response;
    }
}

final class ProfilerBootstrapSqlProvider implements \Lemonade\Framework\Core\BootableServiceProviderInterface
{
    public function boot(\Lemonade\Framework\Container\ContainerInterface $container): void
    {
        $container->get(\Lemonade\Framework\Database\Connection\ConnectionInterface::class)->select('SELECT 21');
    }
}

final class ProfilerUnavailableStoreProvider implements \Lemonade\Framework\Core\ServiceProviderInterface
{
    public function register(\Lemonade\Framework\Container\ContainerBuilderInterface $container): void
    {
        $container->singleton(ProfileStore::class, static function (): never {
            throw new \RuntimeException('private storage initialization failure');
        });
    }
}
