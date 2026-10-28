<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Http\Middleware\MiddlewareStack;
use Lemonade\Profiler\ProfilerMiddleware;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResponseTest extends TestCase
{
    #[DataProvider('excludedResponses')]
    public function testExcludedResponsesKeepBodyAndExistingHeaders(ResponseInterface $original, string $method): void
    {
        $app = new TestApplication();
        try {
            $original->getBody();
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest($method, '/anything'));
            self::assertSame($original->getStatusCode(), $result->getStatusCode());
            self::assertSame($original->getBody(), $result->getBody());
            foreach ($original->getHeaders() as $name => $values) {
                self::assertSame($values, $result->getHeader($name));
            }
            self::assertNotSame('', $result->getHeaderLine('X-Lemonade-Profiler'));
        } finally {
            $app->close();
        }
    }

    /** @return iterable<string, array{ResponseInterface,string}> */
    public static function excludedResponses(): iterable
    {
        $html = '<!doctype html><html><body>Original</body></html>';
        yield 'JSON' => [new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'), 'GET'];
        yield 'fragment' => [new Response(200, ['Content-Type' => 'text/html', 'ETag' => 'original'], '<p>Fragment</p>'), 'GET'];
        yield 'HEAD' => [new Response(200, ['Content-Type' => 'text/html'], $html), 'HEAD'];
        yield 'redirect' => [new Response(302, ['Content-Type' => 'text/html', 'Location' => '/'], $html), 'GET'];
        yield 'download' => [new Response(200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment'], $html), 'GET'];
        yield 'no content' => [new Response(204), 'GET'];
        yield 'not modified' => [new Response(304, ['ETag' => 'original']), 'GET'];
        yield 'encoded' => [new Response(200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'], $html), 'GET'];
        yield 'range' => [new Response(206, ['Content-Type' => 'text/html', 'Content-Range' => 'bytes 0-9/100'], $html), 'GET'];
        yield 'oversized' => [new Response(200, ['Content-Type' => 'text/html'], str_repeat('x', 2097153)), 'GET'];
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('Test socket pair unavailable');
        }
        fwrite($sockets[0], $html);
        fclose($sockets[0]);
        yield 'non-seekable stream' => [new Response(200, ['Content-Type' => 'text/html'], Stream::create($sockets[1])), 'GET'];
    }

    public function testHtmlTransformationReplacesStreamAndInvalidatesBodyValidators(): void
    {
        $app = new TestApplication();
        try {
            $original = new Response(201, ['Content-Type' => 'text/html', 'ETag' => 'before', 'Content-Length' => '52', 'X-Application' => 'preserved'], '<!doctype html><html><body>Public content</body></html>');
            $original->getBody()->seek(5);
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest('GET', '/anything'));
            self::assertSame(201, $result->getStatusCode());
            self::assertNotSame($original->getBody(), $result->getBody());
            self::assertSame(5, $original->getBody()->tell());
            self::assertSame('preserved', $result->getHeaderLine('X-Application'));
            self::assertFalse($result->hasHeader('ETag'));
            self::assertSame((string) strlen((string) $result->getBody()), $result->getHeaderLine('Content-Length'));
            self::assertSame('no-store', $result->getHeaderLine('Cache-Control'));
        } finally {
            $app->close();
        }
    }

    private function supplyResponse(TestApplication $app, ResponseInterface $response): void
    {
        $app->container->scoped(FixedResponseMiddleware::class, static fn(): FixedResponseMiddleware => new FixedResponseMiddleware($response));
        $app->kernel->framework()->middleware(static function (MiddlewareStack $stack): void {
            $stack->insertAfter(ProfilerMiddleware::class, FixedResponseMiddleware::class);
        });
    }
}

final readonly class FixedResponseMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseInterface $response) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->response;
    }
}
