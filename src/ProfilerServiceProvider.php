<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\Context\ApplicationContext;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Filesystem\Contract\DirectoryManagerInterface;
use Lemonade\Framework\Filesystem\Contract\FileManagerInterface;
use Lemonade\Framework\Filesystem\Contract\LockManagerInterface;
use Lemonade\Framework\Filesystem\Manager\DirectoryManager;
use Lemonade\Framework\Filesystem\Manager\FileManager;
use Lemonade\Framework\Filesystem\Manager\LockManager;
use Lemonade\Framework\Http\Middleware\MiddlewareStack;
use Lemonade\Framework\Observability\Http\HttpDiagnosticsObserverFactoryInterface;
use Lemonade\Framework\Observability\Http\HttpDiagnosticsObserverInterface;
use Lemonade\Framework\Observability\Http\Internal\HttpInstrumentation;
use Lemonade\Framework\View\ViewRendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Registers optional HTTP diagnostics only for a development application context.
 */
final readonly class ProfilerServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface, HttpDiagnosticsObserverFactoryInterface
{
    /**
     * Receives only the immutable, bootstrap-safe application context.
     */
    public function __construct(
        private ApplicationContext $context,
    ) {}

    /**
     * Creates capture before bootstrap only in development and outside the profiler's own URLs.
     */
    public function create(ContainerInterface $container, ServerRequestInterface $request, int|float $startedNs): ?HttpDiagnosticsObserverInterface
    {
        if (!$this->context->isDevelopment()) {
            return null;
        }
        $config = $container instanceof ContainerBuilderInterface && $container->isBound(ProfilerConfig::class)
            ? $container->get(ProfilerConfig::class)
            : new ProfilerConfig();
        $path = $request->getUri()->getPath();
        if (!$config->enabled || $path === ProfilerConfig::PREFIX || str_starts_with($path, ProfilerConfig::PREFIX . '/')) {
            return null;
        }

        return new ProfilerCapture(
            new ProfileContext($config, new SqlSanitizer(), $startedNs),
            fn(): ProfileStore => $container instanceof ContainerBuilderInterface && $container->isBound(ProfileStore::class)
                ? $container->get(ProfileStore::class)
                : self::makeStore($container, $this->context, $config),
            new ProfilerResponseDecorator($config, new Psr17Factory()),
        );
    }

    /**
     * Defines request-local capture and decorates, rather than replaces, the application's renderer.
     */
    public function register(ContainerBuilderInterface $container): void
    {
        if (!$this->context->isDevelopment() || !$container->isBound(MiddlewareStack::class)) {
            return;
        }
        if (!$container->isBound(ProfilerConfig::class)) {
            $container->singleton(ProfilerConfig::class, new ProfilerConfig());
        }
        if (!$container->get(ProfilerConfig::class)->enabled) {
            return;
        }
        $container->singleton(SqlSanitizer::class, SqlSanitizer::class);
        $container->scoped(ProfileContext::class, static function (ContainerInterface $container): ProfileContext {
            $capture = $container->get(HttpInstrumentation::class)->observer();

            return $capture instanceof ProfilerCapture
                ? $capture->context()
                : new ProfileContext($container->get(ProfilerConfig::class), $container->get(SqlSanitizer::class));
        });
        $container->scoped(ProfilerMiddleware::class, static fn(ContainerInterface $container): ProfilerMiddleware => new ProfilerMiddleware(
            $container->get(ApplicationContext::class),
            $container->get(ProfilerConfig::class),
            static fn(): ProfilerRequestHandler => $container->get(ProfilerRequestHandler::class),
        ));
        $container->singleton(ProfilerUi::class, ProfilerUi::class);
        $container->singleton(ProfilerRequestHandler::class, ProfilerRequestHandler::class);
        $context = $this->context;
        $container->singleton(ProfileStore::class, static fn(ContainerInterface $container): ProfileStore => self::makeStore(
            $container,
            $context,
            $container->get(ProfilerConfig::class),
        ));
        $container->decorate(ViewRendererInterface::class, static function (ContainerInterface $container, mixed $inner): ViewRendererInterface {
            assert($inner instanceof ViewRendererInterface);
            return new ProfiledViewRenderer($inner, $container->get(ProfileContext::class));
        });
    }

    /**
     * Prepends the profiler after provider registration; place this provider last in application configuration.
     */
    public function boot(ContainerInterface $container): void
    {
        if (!$this->context->isDevelopment() || !$container->has(MiddlewareStack::class)) {
            return;
        }
        if (!$container->get(ProfilerConfig::class)->enabled) {
            return;
        }
        $stack = $container->get(MiddlewareStack::class);
        $stack->remove(ProfilerMiddleware::class)->prepend(ProfilerMiddleware::class);
    }

    private static function makeStore(ContainerInterface $container, ApplicationContext $context, ProfilerConfig $config): ProfileStore
    {
        $directories = $container->has(DirectoryManagerInterface::class)
            ? $container->get(DirectoryManagerInterface::class)
            : new DirectoryManager();

        return new ProfileStore(
            $context->resolveWritablePath('profiler'),
            $context->publicPath(),
            $config,
            $directories,
            $container->has(FileManagerInterface::class) ? $container->get(FileManagerInterface::class) : new FileManager(),
            $container->has(LockManagerInterface::class) ? $container->get(LockManagerInterface::class) : new LockManager($directories),
        );
    }
}
