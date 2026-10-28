<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Http;

use Closure;

use Lemonade\Framework\Http\HttpStatus;
use Lemonade\Profiler\ProfilerConfig;
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
        private ProfilerConfig $config,
        private Closure $profiler,
        /**
         * @var Closure(): void|null
         */
        private ?Closure $captureSession = null,
    ) {}

    /**
     * Intercepts only the profiler's own URL namespace and otherwise preserves application handling.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->config->enabled()
            && ($path === ProfilerConfig::PREFIX || str_starts_with($path, ProfilerConfig::PREFIX . '/'))) {
            try {
                return ($this->profiler)()->handle($request);
            } catch (Throwable) {
                return new Response(HttpStatus::SERVICE_UNAVAILABLE->value, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], $request->getMethod() === 'HEAD' ? '' : 'Profiler is unavailable');
            }
        }

        try {
            return $handler->handle($request);
        } finally {
            try {
                ($this->captureSession)?->__invoke();
            } catch (Throwable) {
                // Optional diagnostics must preserve application behavior.
            }
        }
    }
}
