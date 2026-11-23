<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Client\GuzzleReportSender;
use Webium\ComposerReport\Core\Client\SendResult;
use Webium\ComposerReport\Core\Security\Secret;

class GuzzleReportSenderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const ENDPOINT = 'https://monitor.example.com/composer-report/ingest';
    private const TOKEN = 'crt_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * @var list<array{request: Request, options: array<string, mixed>}>
     */
    private array $history = [];

    public function testAcceptedReport(): void
    {
        $result = $this->send(new Response(202, ['Content-Type' => 'application/json'], '{"status":"accepted"}'));

        $this->assertTrue($result->delivered);
        $this->assertSame(202, $result->statusCode);
        $this->assertSame('Report sent to monitor (HTTP 202).', $result->message);
    }

    public function testRequestHeadersBodyAndOptions(): void
    {
        $this->send(new Response(202));

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $options = $this->history[0]['options'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT, (string) $request->getUri());
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('example-project', $request->getHeaderLine('X-Composer-Report-Project'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('webium-composer-report/', $request->getHeaderLine('User-Agent'));
        $this->assertSame('{"schema_version":1}', (string) $request->getBody());

        $this->assertNotFalse($options['verify'] ?? null, 'TLS verification must stay on');
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);
        $this->assertSame(15.0, $options['timeout']);
        $this->assertSame(5.0, $options['connect_timeout']);
    }

    public function testConnectTimeoutIsNotLongerThanTheTimeout(): void
    {
        $this->send(new Response(202), 2.0);

        $this->assertSame(2.0, $this->history[0]['options']['connect_timeout']);
    }

    public function testRejectedToken(): void
    {
        $result = $this->send(new Response(401, ['Content-Type' => 'application/json'], '{"error":"unauthorized"}'));

        $this->assertFalse($result->delivered);
        $this->assertSame('Monitor rejected the token (HTTP 401).', $result->message);
    }

    public function testBasicAuthInFrontOfTheMonitor(): void
    {
        $result = $this->send(new Response(401, ['WWW-Authenticate' => 'Basic realm="SilverStripe"'], 'Unauthorized'));

        $this->assertSame(
            'Monitor asks for basic auth (HTTP 401); exclude the ingest endpoint from basic auth.',
            $result->message,
        );
    }

    public function testRedirectIsNotFollowed(): void
    {
        $location = 'https://www.monitor.example.com/composer-report/ingest';

        $result = $this->send(new Response(302, ['Location' => $location]), 15.0, new Response(202));

        $this->assertFalse($result->delivered);
        $this->assertSame(302, $result->statusCode);
        $this->assertStringContainsString('to ' . $location, $result->message);
        $this->assertStringContainsString('check the endpoint', $result->message);
        $this->assertCount(1, $this->history);
    }

    public function testJsonErrorFromTheMonitor(): void
    {
        $result = $this->send(new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_payload"}'));

        $this->assertSame('Monitor rejected the report (HTTP 400): invalid_payload', $result->message);
    }

    public function testHtmlErrorBody(): void
    {
        $html = '<html><head><title>413 Request Entity Too Large</title></head><body>'
            . '<center><h1>413 Request Entity Too Large</h1></center><hr><center>nginx</center>'
            . str_repeat('<p>padding</p>', 100) . '</body></html>';

        $result = $this->send(new Response(413, ['Content-Type' => 'text/html'], $html));

        $this->assertFalse($result->delivered);
        $this->assertStringStartsWith(
            'Monitor rejected the report (HTTP 413): 413 Request Entity Too Large',
            $result->message,
        );
        $this->assertStringNotContainsString('<', $result->message);
        $this->assertLessThanOrEqual(250, strlen($result->message));
    }

    public function testEmptyErrorBody(): void
    {
        $result = $this->send(new Response(500));

        $this->assertSame('Monitor rejected the report (HTTP 500): empty response', $result->message);
    }

    public function testConnectionFailure(): void
    {
        $exception = new ConnectException('cURL error 6: Could not resolve host', new Request('POST', self::ENDPOINT));

        $result = $this->send($exception);

        $this->assertFalse($result->delivered);
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('Could not reach the monitor: cURL error 6', $result->message);
        $this->assertStringContainsString('HTTPS_PROXY', $result->message);
        $this->assertStringNotContainsString(self::TOKEN, $result->message);
    }

    private function send(Response|ConnectException $response, float $timeout = 15.0, Response ...$more): SendResult
    {
        $stack = HandlerStack::create(new MockHandler([$response, ...$more]));
        $stack->push(Middleware::history($this->history));

        return (new GuzzleReportSender(new Client(['handler' => $stack])))->send(
            self::ENDPOINT,
            'example-project',
            Secret::fromString(self::TOKEN),
            '{"schema_version":1}',
            $timeout,
        );
    }
}
