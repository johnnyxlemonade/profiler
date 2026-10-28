<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Lemonade\Framework\Core\Context\ApplicationContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves a fixed set of local diagnostic resources without application routing or sessions.
 */
final readonly class ProfilerRequestHandler implements RequestHandlerInterface
{
    /**
     * Uses root-safe read services; the handler holds no current request state.
     */
    public function __construct(
        private ApplicationContext $application,
        private ProfilerConfig $config,
        private ProfileStore $store,
        private ProfilerUi $ui,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Exposes only development GET/HEAD resources; expired or invalid profile IDs return 404.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->application->isDevelopment() || !$this->config->enabled) {
            return $this->response(404, 'Not found');
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->response(405, 'Method not allowed')->withHeader('Allow', 'GET, HEAD');
        }
        try {
            $path = substr($request->getUri()->getPath(), strlen(ProfilerConfig::PREFIX));
            if ($path === '' || $path === '/') {
                $response = $this->response(200, $this->ui->history($this->store->history()));
            } elseif ($path === '/assets/profiler.css') {
                $response = $this->response(200, $this->ui->css(), 'text/css; charset=utf-8');
            } elseif (preg_match('~^/(request|toolbar)/([a-f0-9]{32})$~D', $path, $parts) === 1) {
                $profile = $this->store->find($parts[2]);
                $response = $profile === null
                    ? $this->response(404, 'Profile unavailable or expired')
                    : $this->response(200, $this->ui->profile($profile, $parts[1] === 'toolbar'));
            } else {
                $response = $this->response(404, 'Not found');
            }
        } catch (Throwable) {
            $response = $this->response(503, 'Profiler storage is unavailable');
        }
        return $request->getMethod() === 'HEAD'
            ? $response->withBody($this->streams->createStream(''))
            : $response;
    }

    private function response(int $status, string $body, string $type = 'text/html; charset=utf-8'): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'self' 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'")
            ->withBody($this->streams->createStream($body));
    }
}
