<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Framework\Observability\Http\Internal\HttpInstrumentation;
use Lemonade\Framework\View\ViewRendererInterface;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfiledViewRenderer;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LifecycleTest extends TestCase
{
    public function testEscapingExceptionReleasesObserverBeforeKernelLifecycleEnds(): void
    {
        $app = new TestApplication();
        try {
            $response = $app->kernel->run(new ServerRequest('GET', '/error'));
            self::assertSame(500, $response->getStatusCode());
            self::assertNull($app->container->get(HttpInstrumentation::class)->observer());
            self::assertSame(200, $app->kernel->run(new ServerRequest('GET', '/page/1'))->getStatusCode());
            self::assertNull($app->container->get(HttpInstrumentation::class)->observer());
        } finally {
            $app->close();
        }
    }

    public function testRendererPreservesResultsDataAndExceptions(): void
    {
        $context = new ProfileContext(config: new ProfilerConfig(), sanitizer: new SqlSanitizer(), startedNs: hrtime(true));
        $inner = $this->createMock(ViewRendererInterface::class);
        $response = new Response(202, [], 'unchanged');
        $inner->expects(self::once())->method('render')->with('page', ['secret' => 'private'], 202)->willReturn($response);
        $exception = new RuntimeException('secret-template-error');
        $inner->expects(self::once())->method('content')->with('broken', ['secret' => 'private'])->willThrowException($exception);
        $renderer = new ProfiledViewRenderer($inner, $context);
        self::assertSame($response, $renderer->render('page', ['secret' => 'private'], 202));
        try {
            $renderer->content('broken', ['secret' => 'private']);
            self::fail('Renderer exception must propagate');
        } catch (RuntimeException $actual) {
            self::assertSame($exception, $actual);
        }
        $context->completed(new HttpMetrics(durationMs: 10.0, phpPeakMemoryBytes: 1024, sqlCount: 0, sqlMs: 0.0));
        $profile = $context->snapshot('GET', 202, null);
        self::assertSame([true, false], array_column($profile->toArray()['renderings'], 'completed'));
        self::assertSame([1, 2], array_column($profile->toArray()['renderings'], 'order'));
        self::assertStringNotContainsString('private', json_encode($profile->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret', json_encode($profile->toArray(), JSON_THROW_ON_ERROR));
    }
}
