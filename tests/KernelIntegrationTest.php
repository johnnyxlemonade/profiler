<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Core\Context\Environment;
use Lemonade\Framework\Observability\Http\Internal\HttpInstrumentation;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\ProfilerMiddleware;
use Lemonade\Profiler\ProfileStore;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class KernelIntegrationTest extends TestCase
{
    private TestApplication $app;

    protected function setUp(): void
    {
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
            self::assertSame(2, $profile->data['sql_count']);
            self::assertCount(2, $profile->data['queries']);
            self::assertCount(1, $profile->data['renderings']);
            self::assertSame('page', $profile->data['renderings'][0]['template']);
            self::assertTrue($profile->data['renderings'][0]['completed']);
            self::assertSame('fixture.page', $profile->data['route']);
            self::assertSame('/page/{id}', $profile->data['path']);
            self::assertGreaterThan(0, $profile->data['duration_ms']);
            self::assertGreaterThan(0, $profile->data['peak_bytes']);
            $json = file_get_contents($this->app->root . '/storage/writable/profiler/' . $profile->data['id'] . '.json');
            self::assertIsString($json);
            self::assertStringNotContainsString('secret-', $json);
            self::assertStringNotContainsString('bindings', $json);
        }
        self::assertNull($this->app->container->get(HttpInstrumentation::class)->observer());
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
        $data = $profiles[0]->data;
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
        self::assertSame([404, 200], array_column(array_column($profiles, 'data'), 'status'));
        self::assertSame([0, 0], array_column(array_column($profiles, 'data'), 'sql_count'));
        self::assertNotContains('bootstrap_started', array_column($profiles[1]->data['marks'], 'name'));
    }

    public function testProfilerResourcesDoNotCreateProfiles(): void
    {
        $response = $this->app->kernel->run(new ServerRequest('GET', '/page/1'));
        $detail = $response->getHeaderLine('X-Lemonade-Profiler');
        foreach (['/_lemonade/profiler', $detail, '/_lemonade/profiler/assets/profiler.css'] as $path) {
            $resource = $this->app->kernel->run(new ServerRequest('GET', $path));
            self::assertSame(200, $resource->getStatusCode());
            self::assertSame('no-store', $resource->getHeaderLine('Cache-Control'));
            self::assertFalse($resource->hasHeader('X-Lemonade-Profiler'));
        }
        self::assertCount(1, $this->app->container->get(ProfileStore::class)->history());
        self::assertSame(405, $this->app->kernel->run(new ServerRequest('POST', '/_lemonade/profiler'))->getStatusCode());
        self::assertSame(404, $this->app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler/request/invalid'))->getStatusCode());
    }

    public function testHandledExceptionKeepsItsStatusAndDoesNotLeakIntoNextRequest(): void
    {
        $error = $this->app->kernel->run(new ServerRequest('GET', '/error'));
        self::assertSame(500, $error->getStatusCode());
        $this->app->kernel->run(new ServerRequest('GET', '/page/clean'));
        $profiles = $this->app->container->get(ProfileStore::class)->history();
        self::assertSame([2, 1], array_column(array_column($profiles, 'data'), 'sql_count'));
        self::assertStringNotContainsString('secret-exception', json_encode($profiles, JSON_THROW_ON_ERROR));
    }

    public function testProductionNeverRegistersProfilerEvenWhenProviderIsConfigured(): void
    {
        $production = new TestApplication(Environment::Production, debug: true);
        try {
            $response = $production->kernel->run(new ServerRequest('GET', '/page/1'));
            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($response->hasHeader('X-Lemonade-Profiler'));
            self::assertFalse($production->container->isBound(ProfilerMiddleware::class));
            self::assertDirectoryDoesNotExist($production->root . '/storage/writable/profiler');
            self::assertSame(404, $production->kernel->run(new ServerRequest('GET', '/_lemonade/profiler'))->getStatusCode());
        } finally {
            $production->close();
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

    public function testExplicitEarlyFactoryStillCapturesConfigurationExactlyOnce(): void
    {
        $application = new TestApplication(earlyObserver: true);
        try {
            self::assertSame(200, $application->kernel->run(new ServerRequest('GET', '/page/1'))->getStatusCode());
            $profiles = $application->container->get(ProfileStore::class)->history();
            self::assertCount(1, $profiles);
            self::assertSame(2, $profiles[0]->data['sql_count']);
            self::assertContains('configuration_loaded', array_column($profiles[0]->data['marks'], 'name'));
        } finally {
            $application->close();
        }
    }

    public function testDisabledProfilerDoesNotRegisterCapture(): void
    {
        $disabled = new TestApplication(config: new ProfilerConfig(enabled: false));
        try {
            self::assertSame(200, $disabled->kernel->run(new ServerRequest('GET', '/page/1'))->getStatusCode());
            self::assertFalse($disabled->container->isBound(ProfilerMiddleware::class));
        } finally {
            $disabled->close();
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
