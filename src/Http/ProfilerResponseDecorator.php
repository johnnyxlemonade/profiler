<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Http;

use Lemonade\Framework\Http\HttpStatus;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Adds the profiler link and optional toolbar after the common HTTP metrics have been frozen.
 */
final readonly class ProfilerResponseDecorator
{
    /**
     * Uses bounded response transformation and the registered presentation service.
     */
    public function __construct(
        private ProfilerConfig $config,
        private StreamFactoryInterface $streams,
        private ProfilerUi $ui,
    ) {}

    /**
     * Adds diagnostics to any response and a toolbar only to eligible HTML documents.
     * Failures propagate to the optional HTTP observer boundary, which retains the original response.
     */
    public function decorate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        ?Profile $profile = null,
    ): ResponseInterface {
        if ($profile !== null && $this->acceptsToolbar(request: $request, response: $response)) {
            $html = $this->readHtml($response->getBody());
            if ($html !== null) {
                $html = $this->insertToolbar(html: $html, profile: $profile);
                if ($html !== null) {
                    $response = $this->replaceHtml(response: $response, html: $html);
                }
            }
        }
        return $this->withProfilerHeaders(response: $response, id: $id, profile: $profile);
    }

    /**
     * Excludes responses whose HTTP semantics forbid changing the representation.
     */
    private function acceptsToolbar(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        if ($request->getMethod() === 'HEAD' || $status < HttpStatus::OK->value
            || in_array($status, [HttpStatus::NO_CONTENT->value, 205, 206], true)
            || ($status >= HttpStatus::MULTIPLE_CHOICES->value && $status < HttpStatus::BAD_REQUEST->value)) {
            return false;
        }
        foreach (['Content-Disposition', 'Content-Encoding', 'Content-Range', 'Transfer-Encoding'] as $header) {
            if ($response->hasHeader($header)) {
                return false;
            }
        }
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        return $type === 'text/html';
    }

    /**
     * Reads at most the HTML limit plus one byte and restores the original cursor even on failure.
     * Unknown-size, unsupported, stalled and oversized streams are left unmodified.
     */
    private function readHtml(StreamInterface $body): ?string
    {
        $limit = $this->config->maxHtmlBytes();
        $size = $body->getSize();
        if (!$body->isReadable() || !$body->isSeekable() || $size === null || $size > $limit) {
            return null;
        }
        $position = $body->tell();
        try {
            $body->rewind();
            $html = '';
            while (!$body->eof()) {
                $chunk = $body->read($limit + 1 - strlen($html));
                if ($chunk === '') {
                    return null;
                }
                $html .= $chunk;
                if (strlen($html) > $limit) {
                    return null;
                }
            }
            return $html;
        } finally {
            $body->seek($position);
        }
    }

    /**
     * Inserts the module before the closing body only when a complete HTML document is present.
     */
    private function insertToolbar(string $html, Profile $profile): ?string
    {
        if (preg_match('/^\s*(?:<!doctype html[^>]*>\s*)?<html(?:\s|>).*?<body(?:\s|>)/is', $html) !== 1) {
            return null;
        }
        $end = strripos($html, '</body>');
        if ($end === false || stripos($html, '</html>', $end) === false) {
            return null;
        }
        return substr_replace($html, $this->toolbarMarkup($profile), $end, 0);
    }

    /**
     * Escapes the JSON attribute and uses the existing external module without changing CSP.
     */
    private function toolbarMarkup(Profile $profile): string
    {
        $data = json_encode($this->ui->toolbarData($profile), JSON_THROW_ON_ERROR);
        $payload = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<script type="module" src="' . ProfilerConfig::PREFIX . '/assets/toolbar.js" data-lemonade-profile="' . $payload . '"></script>';
    }

    /**
     * Replaces the body, discards stale representation validators and prevents caching the toolbar.
     */
    private function replaceHtml(ResponseInterface $response, string $html): ResponseInterface
    {
        $response = $response->withBody($this->streams->createStream($html));
        foreach (['ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest', 'Last-Modified'] as $header) {
            $response = $response->withoutHeader($header);
        }
        return $response
            ->withHeader('Content-Length', (string) strlen($html))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Adds profile discovery and measured totals independently of HTML injection.
     */
    private function withProfilerHeaders(ResponseInterface $response, string $id, ?Profile $profile): ResponseInterface
    {
        $response = $response->withHeader('X-Lemonade-Profiler', ProfilerConfig::PREFIX . '/request/' . $id);
        if ($profile !== null) {
            $metadata = json_encode($this->ui->summary($profile), JSON_THROW_ON_ERROR);
            $response = $response->withHeader('X-Lemonade-Profiler-Metrics', rawurlencode($metadata));
        }
        return $response;
    }
}
