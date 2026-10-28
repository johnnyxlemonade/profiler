<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Closure;
use Lemonade\Framework\Core\Context\ApplicationContext;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves internal profiler resources; measurement belongs exclusively to the outer HTTP boundary.
 */
final readonly class ProfilerMiddleware implements MiddlewareInterface
{
    /**
     * Resolves UI dependencies only for internal requests, so their failures cannot break application routes.
     *
     * @param Closure(): ProfilerRequestHandler $profiler
     */
    public function __construct(
        private ApplicationContext $application,
        private ProfilerConfig $config,
        private Closure $profiler,
    ) {}

    /**
     * Intercepts only the profiler's own URL namespace and otherwise preserves application handling.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->application->isDevelopment() && $this->config->enabled
            && ($path === ProfilerConfig::PREFIX || str_starts_with($path, ProfilerConfig::PREFIX . '/'))) {
            try {
                return ($this->profiler)()->handle($request);
            } catch (Throwable) {
                return new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], $request->getMethod() === 'HEAD' ? '' : 'Profiler storage is unavailable');
            }
        }

        return $handler->handle($request);
    }
}
