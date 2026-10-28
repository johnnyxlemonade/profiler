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
use Lemonade\Framework\Session\Config\SessionConfig;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Framework\View\ViewRendererInterface;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\ProfilerCapture;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Http\ProfilerMiddleware;
use Lemonade\Profiler\Http\ProfilerRequestHandler;
use Lemonade\Profiler\Http\ProfilerResponseDecorator;
use Lemonade\Profiler\Presentation\ProfiledViewRenderer;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Storage\ProfileStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers optional HTTP diagnostics when enabled in the application configuration.
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
     * Creates capture before bootstrap when enabled and outside the profiler's own URLs.
     */
    public function create(ContainerInterface $container, ServerRequestInterface $request, int|float $startedNs): ?HttpDiagnosticsObserverInterface
    {
        $config = $container->get(ProfilerConfig::class);
        $path = $request->getUri()->getPath();
        if (!$config->enabled() || $path === ProfilerConfig::PREFIX || str_starts_with($path, ProfilerConfig::PREFIX . '/')) {
            return null;
        }

        return new ProfilerCapture(
            context: new ProfileContext(
                config: $config,
                sanitizer: $container->get(SqlSanitizer::class),
                startedNs: $startedNs,
            ),
            store: fn(): ProfileStore => $container->has(ProfileStore::class)
                ? $container->get(ProfileStore::class)
                : self::makeEarlyStore(container: $container, context: $this->context, config: $config),
            decorator: static function () use ($container, $config): ProfilerResponseDecorator {
                if ($container->has(ProfilerResponseDecorator::class) || $container->has(StreamFactoryInterface::class)) {
                    return $container->get(ProfilerResponseDecorator::class);
                }
                // Before core providers, only the concrete PSR factory is registered.
                return new ProfilerResponseDecorator(
                    config: $config,
                    streams: $container->get(Psr17Factory::class),
                    ui: $container->get(ProfilerUi::class),
                );
            },
            environment: $this->context->environment()->value,
            applicationRoot: $this->context->basePath(),
        );
    }

    /**
     * Defines request-local capture and decorates, rather than replaces, the application's renderer.
     */
    public function register(ContainerBuilderInterface $container): void
    {
        if (!$container->isBound(MiddlewareStack::class)) {
            return;
        }
        if (!$container->get(ProfilerConfig::class)->enabled()) {
            return;
        }
        $container->scoped(ProfileContext::class, static function (ContainerInterface $container): ProfileContext {
            $capture = $container->get(HttpInstrumentation::class)->observer();

            return $capture instanceof ProfilerCapture
                ? $capture->context()
                : new ProfileContext(
                    config: $container->get(ProfilerConfig::class),
                    sanitizer: $container->get(SqlSanitizer::class),
                );
        });
        $container->transient(ProfilerMiddleware::class, static fn(ContainerInterface $container): ProfilerMiddleware => new ProfilerMiddleware(
            config: $container->get(ProfilerConfig::class),
            profiler: static fn(): ProfilerRequestHandler => $container->get(ProfilerRequestHandler::class),
            captureSession: static fn() => $container->get(ProfileContext::class)->captureSession(
                $container->has(SessionConfig::class)
                    ? $container->get(SessionConfig::class) : null,
            ),
        ));
        $container->decorate(SessionInterface::class, static function (ContainerInterface $container, mixed $inner): SessionInterface {
            assert($inner instanceof SessionInterface);
            $container->get(ProfileContext::class)->observeSession($inner);
            return $inner;
        });
        $container->transient(ProfileStore::class, static function (ContainerInterface $container): ProfileStore {
            $context = $container->get(ApplicationContext::class);

            return new ProfileStore(
                directory: $context->resolveWritablePath('profiler'),
                publicRoot: $context->publicPath(),
                config: $container->get(ProfilerConfig::class),
                directories: $container->get(DirectoryManagerInterface::class),
                files: $container->get(FileManagerInterface::class),
                locks: $container->get(LockManagerInterface::class),
            );
        });
        $container->decorate(ViewRendererInterface::class, static function (ContainerInterface $container, mixed $inner): ViewRendererInterface {
            assert($inner instanceof ViewRendererInterface);
            return new ProfiledViewRenderer(
                inner: $inner,
                context: $container->get(ProfileContext::class),
            );
        });
    }

    /**
     * Prepends the profiler after provider registration; place this provider last in application configuration.
     */
    public function boot(ContainerInterface $container): void
    {
        if (!$container->has(MiddlewareStack::class)) {
            return;
        }
        if (!$container->get(ProfilerConfig::class)->enabled()) {
            return;
        }
        $stack = $container->get(MiddlewareStack::class);
        $stack->remove(ProfilerMiddleware::class)->prepend(ProfilerMiddleware::class);
    }

    /**
     * Keeps early HTTP capture writable when bootstrap fails before filesystem bindings exist.
     */
    private static function makeEarlyStore(
        ContainerInterface $container,
        ApplicationContext $context,
        ProfilerConfig $config,
    ): ProfileStore {
        $directories = $container->get(DirectoryManager::class);

        return new ProfileStore(
            directory: $context->resolveWritablePath('profiler'),
            publicRoot: $context->publicPath(),
            config: $config,
            directories: $directories,
            files: $container->get(FileManager::class),
            locks: new LockManager($directories),
        );
    }
}
