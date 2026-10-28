<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Closure;
use Lemonade\Framework\Database\Instrumentation\DatabaseObserverInterface;
use Lemonade\Framework\Observability\Http\HttpCheckpoint;
use Lemonade\Framework\Observability\Http\HttpDiagnosticsObserverInterface;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Framework\Routing\RouteMatch;
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
     */
    public function __construct(
        private ProfileContext $context,
        private Closure $store,
        private ProfilerResponseDecorator $decorator,
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
        $this->context->queryCompleted($sql, $durationMs, $successful);
    }

    /**
     * Keeps lazy connection setup separate from query execution time.
     */
    public function connectionCompleted(float $durationMs, bool $successful): void
    {
        $this->context->connectionCompleted($durationMs, $successful);
    }

    /**
     * Saves the finished profile before adding links or modifying an eligible HTML response.
     */
    public function response(ServerRequestInterface $request, ResponseInterface $response, ?RouteMatch $match): ResponseInterface
    {
        $profile = $this->context->snapshot($request->getMethod(), $response->getStatusCode(), $match);
        ($this->store)()->save($profile);

        return $this->decorator->decorate($request, $response, $profile->data['id']);
    }
}
