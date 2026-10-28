<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Capture;

use Closure;
use Lemonade\Framework\Database\Instrumentation\DatabaseObserverInterface;

use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use Lemonade\Framework\Observability\Http\HttpDiagnosticsObserverInterface;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Framework\Routing\RouteMatch;
use Lemonade\Profiler\Http\ProfilerResponseDecorator;
use Lemonade\Profiler\Storage\ProfileStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts one request's bounded profile to the framework's HTTP and SQL observation contracts.
 */
final readonly class ProfilerCapture implements HttpDiagnosticsObserverInterface, DatabaseObserverInterface
{
    /**
     * Defers storage resolution until after bootstrap and completion of the measured interval.
     *
     * @param Closure(): ProfileStore $store
     * @param Closure(): ProfilerResponseDecorator $decorator
     */
    public function __construct(
        private ProfileContext $context,
        private Closure $store,
        private Closure $decorator,
        private string $environment = 'dev',
        private ?string $applicationRoot = null,
    ) {}

    /**
     * Shares capture with the request-scoped renderer decorator.
     */
    public function context(): ProfileContext
    {
        return $this->context;
    }

    /**
     * Retains the framework's real checkpoint without taking another timestamp.
     */
    public function checkpoint(HttpCheckpoint $checkpoint, float $elapsedMs): void
    {
        $this->context->checkpoint($checkpoint, $elapsedMs);
    }

    /**
     * Uses the same immutable summary as response diagnostics and request logging.
     */
    public function completed(HttpMetrics $metrics): void
    {
        $this->context->completed($metrics);
    }

    /**
     * Sanitizes and bounds SQL details without recomputing aggregate measurements.
     */
    public function queryCompleted(string $sql, float $durationMs, bool $successful): void
    {
        $this->context->queryCompleted(sql: $sql, elapsedMs: $durationMs, successful: $successful);
    }

    /**
     * Keeps lazy connection setup separate from query execution time.
     */
    public function connectionCompleted(float $durationMs, bool $successful): void
    {
        $this->context->connectionCompleted(elapsedMs: $durationMs, successful: $successful);
    }

    /**
     * Saves the finished profile before adding links or modifying an eligible HTML response.
     */
    public function response(ServerRequestInterface $request, ResponseInterface $response, ?RouteMatch $match): ResponseInterface
    {
        $this->context->captureRuntime($this->environment, $request->getServerParams()['SERVER_SOFTWARE'] ?? null, $this->applicationRoot);
        $profile = $this->context->snapshot(
            method: $request->getMethod(),
            status: $response->getStatusCode(),
            match: $match,
        );
        ($this->store)()->save($profile);

        return ($this->decorator)()->decorate(
            request: $request,
            response: $response,
            id: $profile->id(),
            profile: $profile,
        );
    }
}
