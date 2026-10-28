<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Container\ScopeKind;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Http\ProfilerMiddleware;
use Lemonade\Profiler\Http\ProfilerRequestHandler;
use Lemonade\Profiler\Http\ProfilerResponseDecorator;
use Lemonade\Profiler\Presentation\ProfilerAssets;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Storage\ProfileStore;
use Lemonade\Profiler\Tests\Support\TestApplication;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function testStatelessServicesUseImplicitTransientAutowiring(): void
    {
        $app = new TestApplication();
        try {
            $app->kernel->bootstrap();
            foreach ([
                ProfilerAssets::class, ProfilerTimeline::class, ProfilerUi::class,
                ProfilerRequestHandler::class, ProfilerResponseDecorator::class,
                SqlSanitizer::class, ProfilerConfig::class,
            ] as $service) {
                self::assertFalse($app->container->isBound($service), $service);
                $first = $app->container->get($service);
                self::assertInstanceOf($service, $first);
                self::assertNotSame($first, $app->container->get($service));
            }
            self::assertNotSame($app->container->get(ProfileStore::class), $app->container->get(ProfileStore::class));
        } finally {
            $app->close();
        }
    }

    public function testOnlyCaptureStateRequiresRequestIdentity(): void
    {
        $app = new TestApplication();
        try {
            $app->kernel->bootstrap();
            $first = $app->container->beginScope(ScopeKind::Request);
            $second = $app->container->beginScope(ScopeKind::Request);
            try {
                $context = $first->get(ProfileContext::class);
                self::assertSame($context, $first->get(ProfileContext::class));
                self::assertNotSame($context, $second->get(ProfileContext::class));
                self::assertNotSame($first->get(ProfilerMiddleware::class), $first->get(ProfilerMiddleware::class));
                // Without an active observer, a scoped context must remain inert.
                self::assertNull($context->beginRendering('unobserved'));
            } finally {
                $first->close();
                $second->close();
            }
        } finally {
            $app->close();
        }
    }

    public function testApplicationBindingsAreNotReplacedByRedundantDefaults(): void
    {
        $config = new ProfilerConfig(maxQueries: 3);
        $app = new TestApplication(config: $config);
        try {
            $ui = new ProfilerUi(new ProfilerTimeline());
            $sanitizer = new SqlSanitizer();
            $app->container->instance(ProfilerUi::class, $ui);
            $app->container->instance(SqlSanitizer::class, $sanitizer);
            $app->kernel->bootstrap();
            self::assertSame($config, $app->container->get(ProfilerConfig::class));
            self::assertSame($ui, $app->container->get(ProfilerUi::class));
            self::assertSame($sanitizer, $app->container->get(SqlSanitizer::class));
        } finally {
            $app->close();
        }
    }
}
