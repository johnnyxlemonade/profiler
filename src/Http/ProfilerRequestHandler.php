<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Http;

use Lemonade\Framework\Http\HttpStatus;
use Lemonade\Profiler\Presentation\ProfilerAssets;
use Lemonade\Profiler\Presentation\ProfilerTranslator;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Storage\ProfileStore;
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
        private ProfilerConfig $config,
        private ProfileStore $store,
        private ProfilerUi $ui,
        private ProfilerAssets $assets,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Exposes enabled GET/HEAD resources; expired or invalid profile IDs return 404.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $explicit = $query['lang'] ?? null;
        $locale = ProfilerTranslator::choose(is_string($explicit) ? $explicit : null, $request->getHeaderLine('Accept-Language'));
        $translator = new ProfilerTranslator($locale);
        if (!$this->config->enabled()) {
            return $this->response(HttpStatus::NOT_FOUND, $translator->translate('errors.not_found'));
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->response(HttpStatus::METHOD_NOT_ALLOWED, $translator->translate('errors.method_not_allowed'))->withHeader('Allow', 'GET, HEAD');
        }
        $path = substr($request->getUri()->getPath(), strlen(ProfilerConfig::PREFIX));
        $response = match (true) {
            $path === '' || $path === '/' => $this->history($translator),
            str_starts_with($path, '/assets/') => $this->asset(
                name: substr($path, strlen('/assets/')),
                translator: $translator,
            ),
            preg_match('~^/request/([a-f0-9]{32})$~D', $path, $parts) === 1 => $this->detail($parts[1], $translator),
            default => $this->notFound($translator),
        };

        return $request->getMethod() === 'HEAD'
            ? $response->withBody($this->streams->createStream(''))
            : $response;
    }

    /**
     * Reads retained snapshots before rendering the history page; storage failures become 503.
     */
    private function history(ProfilerTranslator $translator): ResponseInterface
    {
        try {
            $profiles = $this->store->history();
        } catch (Throwable) {
            return $this->storageUnavailable($translator);
        }

        return $this->response(
            status: HttpStatus::OK,
            body: $this->ui->history($profiles, $translator->locale()),
            allowScripts: true,
        );
    }

    /**
     * Reads one validated profile ID and renders its detail page when still retained.
     */
    private function detail(string $id, ProfilerTranslator $translator): ResponseInterface
    {
        try {
            $profiles = $this->store->history();
        } catch (Throwable) {
            return $this->storageUnavailable($translator);
        }

        $profile = null;
        foreach ($profiles as $candidate) {
            if ($candidate->id() === $id) {
                $profile = $candidate;
                break;
            }
        }

        return $profile === null
            ? $this->response(HttpStatus::NOT_FOUND, $translator->translate('errors.profile_unavailable'))
            : $this->response(HttpStatus::OK, $this->ui->profile($profile, $translator->locale(), $profiles));
    }

    /**
     * Serves only package assets named in the fixed allowlist.
     */
    private function asset(string $name, ProfilerTranslator $translator): ResponseInterface
    {
        $asset = $this->assets->load($name);
        if ($asset === null) {
            return $this->notFound($translator);
        }

        return $this->response(
            status: HttpStatus::OK,
            body: $asset['content'],
            type: $asset['type'],
        );
    }

    /**
     * Returns a localized response for unknown or disabled profiler URLs.
     */
    private function notFound(ProfilerTranslator $translator): ResponseInterface
    {
        return $this->response(HttpStatus::NOT_FOUND, $translator->translate('errors.not_found'));
    }

    /**
     * Reports a storage read failure without exposing its internal cause.
     */
    private function storageUnavailable(ProfilerTranslator $translator): ResponseInterface
    {
        return $this->response(HttpStatus::SERVICE_UNAVAILABLE, $translator->translate('errors.storage_unavailable'));
    }

    /**
     * Applies the same private-resource security headers to every handler response.
     */
    private function response(HttpStatus $status, string $body, string $type = 'text/html; charset=utf-8', bool $allowScripts = false): ResponseInterface
    {
        $csp = "default-src 'none'; style-src 'self'; img-src 'self';"
            . ($allowScripts ? " script-src 'self';" : '')
            . " frame-ancestors 'self'; base-uri 'none'; form-action 'none'";
        return $this->responses->createResponse($status->value)
            ->withHeader('Content-Type', $type)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', $csp)
            ->withBody($this->streams->createStream($body));
    }
}
