<?php

namespace BackQ\Tests\Message;

use BackQ\Message\Guzzle;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use LogicException;
use PHPUnit\Framework\TestCase;

class GuzzleTest extends TestCase
{
    public function testFromRequest(): void
    {
        $request = new Request('POST', 'http://example.com/endpoint', ['X-Test' => '1'], 'body');

        $message = new Guzzle($request);
        $parsed  = $message->getRequest();

        $this->assertSame('POST', $parsed->getMethod());
        $this->assertSame('http', $parsed->getUri()->getScheme());
        $this->assertSame('example.com', $parsed->getUri()->getHost());
        $this->assertSame('1', $parsed->getHeaderLine('X-Test'));
        $this->assertSame('body', (string) $parsed->getBody());
    }

    public function testFromRequestWithHttpsKeepsScheme(): void
    {
        $request = new Request('GET', 'https://example.com/secure', ['Host' => 'example.com']);

        $message = new Guzzle($request);
        $parsed  = $message->getRequest();

        $this->assertSame('https', $parsed->getUri()->getScheme());
        $this->assertSame('example.com', $parsed->getUri()->getHost());
    }

    public function testFromRawRequest(): void
    {
        $rawRequest = Message::toString(new Request('GET', 'http://example.com/path', ['X-Trace' => 'abc'], ''));

        $message = new Guzzle(null, $rawRequest);
        $parsed  = $message->getRequest();

        $this->assertSame('GET', $parsed->getMethod());
        $this->assertSame('/path', $parsed->getRequestTarget());
        $this->assertSame('example.com', $parsed->getUri()->getHost());
        $this->assertSame('abc', $parsed->getHeaderLine('X-Trace'));
    }

    public function testEmptyRequestThrows(): void
    {
        $this->expectException(LogicException::class);

        new Guzzle();
    }
}