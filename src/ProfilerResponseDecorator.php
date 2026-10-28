<?php

declare(strict_types=1);

namespace Lemonade\Profiler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adds the profiler link and optional toolbar after the common HTTP metrics have been frozen.
 */
final readonly class ProfilerResponseDecorator
{
    /**
     * Uses bounded response transformation without changing excluded response bodies.
     */
    public function __construct(
        private ProfilerConfig $config,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Preserves the response representation unless it is a bounded, complete HTML document.
     */
    public function decorate(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->inject($request, $response, $id)
            ->withHeader('X-Lemonade-Profiler', ProfilerConfig::PREFIX . '/request/' . $id);
    }

    private function inject(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $status = $response->getStatusCode();
        $body = $response->getBody();
        $size = $body->getSize();
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if ($request->getMethod() === 'HEAD' || $status < 200 || $status === 204 || $status === 205 || $status === 206 || ($status >= 300 && $status < 400)
            || $type !== 'text/html' || $response->hasHeader('Content-Disposition') || $response->hasHeader('Content-Encoding')
            || $response->hasHeader('Content-Range') || $response->hasHeader('Transfer-Encoding')
            || !$body->isReadable() || !$body->isSeekable() || $size === null || $size > $this->config->maxHtmlBytes) {
            return $response;
        }
        $position = $body->tell();
        try {
            $body->rewind();
            $html = '';
            while (!$body->eof() && strlen($html) <= $this->config->maxHtmlBytes) {
                $chunk = $body->read(min(65536, $this->config->maxHtmlBytes + 1 - strlen($html)));
                if ($chunk === '') {
                    return $response;
                }
                $html .= $chunk;
            }
        } finally {
            $body->seek($position);
        }
        if (strlen($html) > $this->config->maxHtmlBytes || preg_match('/^\s*(?:<!doctype html[^>]*>\s*)?<html(?:\s|>)/i', $html) !== 1 || preg_match('/<body(?:\s|>)/i', $html) !== 1) {
            return $response;
        }
        $end = strripos($html, '</body>');
        if ($end === false) {
            return $response;
        }
        $toolbar = '<iframe title="Lemonade Profiler" src="' . ProfilerConfig::PREFIX . '/toolbar/' . $id . '"'
            . ' sandbox="allow-top-navigation-by-user-activation"'
            . ' style="all:initial!important;display:block!important;position:fixed!important;inset:auto 0 0!important;width:100%!important;height:48px!important;border:0!important;z-index:2147483647!important;color-scheme:light dark!important"></iframe>';
        $html = substr($html, 0, $end) . $toolbar . substr($html, $end);
        return $response->withBody($this->streams->createStream($html))
            ->withoutHeader('ETag')
            ->withoutHeader('Content-MD5')
            ->withoutHeader('Digest')
            ->withoutHeader('Content-Digest')
            ->withoutHeader('Repr-Digest')
            ->withoutHeader('Last-Modified')
            ->withHeader('Content-Length', (string) strlen($html))
            ->withHeader('Cache-Control', 'no-store');
    }
}
