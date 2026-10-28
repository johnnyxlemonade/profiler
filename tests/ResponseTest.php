<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use Lemonade\Framework\Http\Middleware\MiddlewareStack;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Http\ProfilerMiddleware;
use Lemonade\Profiler\Http\ProfilerResponseDecorator;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
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
            self::assertTrue($result->hasHeader('X-Lemonade-Profiler-Metrics'));
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
        yield 'informational' => [new Response(103, ['Content-Type' => 'text/html'], $html), 'GET'];
        yield 'reset content' => [new Response(205, ['Content-Type' => 'text/html'], $html), 'GET'];
        yield 'partial without range header' => [new Response(206, ['Content-Type' => 'text/html'], $html), 'GET'];
        yield 'range header' => [new Response(200, ['Content-Type' => 'text/html', 'Content-Range' => 'bytes 0-9/100'], $html), 'GET'];
        yield 'transfer encoding' => [new Response(200, ['Content-Type' => 'text/html', 'Transfer-Encoding' => 'chunked'], $html), 'GET'];
        yield 'missing closing body' => [new Response(200, ['Content-Type' => 'text/html'], '<html><body>Incomplete</html>'), 'GET'];
        yield 'missing closing html' => [new Response(200, ['Content-Type' => 'text/html'], '<html><body>Incomplete</body>'), 'GET'];
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
            foreach (['Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest', 'Last-Modified'] as $header) {
                $original = $original->withHeader($header, 'before');
            }
            $original->getBody()->seek(5);
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest('GET', '/anything'));
            self::assertSame(201, $result->getStatusCode());
            self::assertNotSame($original->getBody(), $result->getBody());
            self::assertSame(5, $original->getBody()->tell());
            self::assertSame('preserved', $result->getHeaderLine('X-Application'));
            foreach (['ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Repr-Digest', 'Last-Modified'] as $header) {
                self::assertFalse($result->hasHeader($header));
            }
            self::assertSame((string) strlen((string) $result->getBody()), $result->getHeaderLine('Content-Length'));
            self::assertSame('no-store', $result->getHeaderLine('Cache-Control'));
        } finally {
            $app->close();
        }
    }

    public function testRestrictiveCspIsPreservedWithoutWeakeningTheApplicationPolicy(): void
    {
        $app = new TestApplication();
        try {
            $policy = "default-src 'none'; script-src 'nonce-application'; style-src 'self'";
            $original = new Response(200, ['Content-Type' => 'text/html', 'Content-Security-Policy' => $policy], '<html><body>Content</body></html>');
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest('GET', '/anything'));
            self::assertSame($policy, $result->getHeaderLine('Content-Security-Policy'));
            self::assertTrue($result->hasHeader('X-Lemonade-Profiler-Metrics'));
        } finally {
            $app->close();
        }
    }

    #[DataProvider('streamBehaviors')]
    public function testBoundedStreamReadingRestoresCursorAndPreservesResponsesOnFailure(string $behavior): void
    {
        $html = '<html><body>Original</body></html>';
        $source = Stream::create($html);
        $source->seek(5);
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn($behavior === 'unknown' ? null : 0);
        $body->method('isReadable')->willReturn($behavior !== 'unreadable');
        $body->method('isSeekable')->willReturn(true);
        $body->method('tell')->willReturnCallback($source->tell(...));
        $body->method('eof')->willReturnCallback($source->eof(...));
        $body->method('rewind')->willReturnCallback($source->rewind(...));
        $body->method('seek')->willReturnCallback($source->seek(...));
        $readBytes = 0;
        $body->method('read')->willReturnCallback(static function (int $length) use ($source, $behavior, &$readBytes): string {
            if ($behavior === 'stalled') {
                return '';
            }
            $chunk = $source->read(min(3, $length));
            $readBytes += strlen($chunk);
            if ($behavior === 'failure') {
                throw new \RuntimeException('Read failed after moving the cursor.');
            }
            return $chunk;
        });
        $limit = $behavior === 'oversized' ? 10 : strlen($html);
        $app = new TestApplication(config: new ProfilerConfig(maxHtmlBytes: $limit));
        try {
            $original = new Response(200, ['Content-Type' => 'text/html', 'ETag' => 'original'], $body);
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest('GET', '/anything'));
            self::assertSame(5, $source->tell());
            self::assertLessThanOrEqual($limit + 1, $readBytes);
            if ($behavior === 'partial') {
                self::assertNotSame($body, $result->getBody());
                self::assertStringContainsString('data-lemonade-profile=', (string) $result->getBody());
            } else {
                self::assertSame($body, $result->getBody());
                self::assertSame('original', $result->getHeaderLine('ETag'));
            }
            self::assertSame($behavior !== 'failure', $result->hasHeader('X-Lemonade-Profiler'));
        } finally {
            $app->close();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function streamBehaviors(): iterable
    {
        foreach (['partial', 'stalled', 'failure', 'oversized', 'unknown', 'unreadable'] as $behavior) {
            yield $behavior => [$behavior];
        }
    }

    public function testUiResolutionFailurePreservesApplicationResponse(): void
    {
        $app = new TestApplication();
        try {
            file_put_contents($app->root . '/app/Config/Providers.yaml', <<<'PROVIDERS'
module: providers
config:
  providers:
    - Lemonade\Profiler\ProfilerServiceProvider
    - Lemonade\Profiler\Tests\ProfilerUnavailableUiProvider
PROVIDERS);
            $original = new Response(200, ['Content-Type' => 'text/html', 'ETag' => 'original'], '<html><body>Original</body></html>');
            $original->getBody()->seek(5);
            $this->supplyResponse($app, $original);
            $result = $app->kernel->run(new ServerRequest('GET', '/anything'));
            self::assertSame($original->getBody(), $result->getBody());
            self::assertSame(5, $original->getBody()->tell());
            self::assertSame('original', $result->getHeaderLine('ETag'));
            self::assertFalse($result->hasHeader('X-Lemonade-Profiler'));
        } finally {
            $app->close();
        }
    }

    public function testToolbarPayloadSurvivesAttributeEscapingWithoutCreatingMarkup(): void
    {
        $config = new ProfilerConfig();
        $context = new ProfileContext(config: $config, sanitizer: new SqlSanitizer(), startedNs: hrtime(true));
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        $data['controller'] = '"><script>alert("unsafe")</script>&';
        $profile = new Profile($data);
        $ui = new ProfilerUi(new ProfilerTimeline());
        $decorator = new ProfilerResponseDecorator(config: $config, streams: new Psr17Factory(), ui: $ui);
        $result = $decorator->decorate(
            request: new ServerRequest('GET', '/'),
            response: new Response(200, ['Content-Type' => 'TEXT/HTML; charset=UTF-8'], '<html><body>Original</body></html>'),
            id: $profile->id(),
            profile: $profile,
        );
        $dom = new \DOMDocument();
        $dom->loadHTML((string) $result->getBody());
        $scripts = $dom->getElementsByTagName('script');
        self::assertSame(1, $scripts->length);
        $script = $scripts->item(0);
        self::assertInstanceOf(\DOMElement::class, $script);
        self::assertSame('module', $script->getAttribute('type'));
        self::assertSame('/_lemonade/profiler/assets/toolbar.js', $script->getAttribute('src'));
        self::assertSame('', $script->textContent);
        self::assertEquals($ui->toolbarData($profile), json_decode($script->getAttribute('data-lemonade-profile'), true, 32, JSON_THROW_ON_ERROR));
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

final class ProfilerUnavailableUiProvider implements \Lemonade\Framework\Core\ServiceProviderInterface
{
    public function register(\Lemonade\Framework\Container\ContainerBuilderInterface $container): void
    {
        $container->singleton(ProfilerUi::class, static function (): never {
            throw new \RuntimeException('UI unavailable');
        });
    }
}
