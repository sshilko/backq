<?php

namespace BackQ\Tests\Adapter\IO;

use BackQ\Adapter\IO\Exception\RuntimeException;
use BackQ\Adapter\IO\Exception\TimeoutException;
use BackQ\Adapter\IO\StreamIO;
use PHPUnit\Framework\TestCase;
use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function stream_context_create;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

class StreamIOTest extends TestCase
{

    private $server;

    private $port;

    private $accepted;

    private StreamIO $io;

    public function testWrite(): void
    {
        $this->io->write('ping');

        $this->assertSame('ping', fread($this->accepted, 4));
    }

    public function testRead(): void
    {
        fwrite($this->accepted, 'hello');

        $this->assertSame('hello', $this->io->read(5));
    }

    public function testSelectReadReportsAvailableData(): void
    {
        fwrite($this->accepted, 'x');

        $this->assertSame(1, $this->io->selectRead(1, 1000));
    }

    public function testSelectWriteReportsWritable(): void
    {
        $this->assertGreaterThan(0, $this->io->selectWrite(1, 1000));
    }

    public function testStreamGetLine(): void
    {
        fwrite($this->accepted, "abc\r\nrest");

        $this->assertSame('abc', $this->io->stream_get_line(8));
    }

    public function testStreamGetContents(): void
    {
        fwrite($this->accepted, 'hello');

        $this->assertSame('hello', $this->io->stream_get_contents(5));
    }

    public function testStreamSetTimeout(): void
    {
        $this->io->stream_set_timeout(5);

        $this->addToAssertionCount(1);
    }

    public function testReadAfterPeerCloseThrowsTimeout(): void
    {
        fwrite($this->accepted, 'data');
        fclose($this->accepted);
        $this->accepted = null;

        $this->assertSame('data', $this->io->read(4));

        $this->expectException(TimeoutException::class);

        $this->io->read(4);
    }

    public function testConstructorThrowsOnTlsContextWhenPeerUnreachable(): void
    {
        $temp        = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($temp);
        $name        = stream_socket_get_name($temp, false);
        $tempPort    = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        fclose($temp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error Connecting to server');

        new StreamIO('127.0.0.1', $tempPort, 1, 1, stream_context_create(), false, true);
    }

    public function testTimedOutSocketThrowsOnReadsAndWrites(): void
    {
        $io = new StreamIO('127.0.0.1', $this->port, 1, 1, null, true);

        try {
            $io->read(8);
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed to fread() from socket', $e->getMessage());
        }

        $operations = [
            static function () use ($io): mixed {
                return $io->read(8);
            },
            static function () use ($io): mixed {
                return $io->write('x');
            },
            static function () use ($io): mixed {
                return $io->stream_get_line(8);
            },
            static function () use ($io): mixed {
                return $io->stream_get_contents(8);
            },
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('expected a TimeoutException');
            } catch (TimeoutException $e) {
                $this->assertStringContainsString('TIME', $e->getMessage());
            }
        }

        $io->close();
    }

    public function testStreamGetLineThrowsOnEof(): void
    {
        fclose($this->accepted);
        $this->accepted = null;

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Socket connection EOF');

        $this->io->stream_get_line(8);
    }

    public function testCloseIsRepeatedSafe(): void
    {
        $this->io->close();
        $this->io->close();

        $this->addToAssertionCount(1);
    }

    public function testConstructorFailsWhenPeerIsDown(): void
    {
        fclose($this->server);
        $this->server = null;

        $this->expectException(RuntimeException::class);

        $io = new StreamIO('127.0.0.1', $this->port, 1);
        $io->close();
    }

    public function testIsSocketReadyReturnsFalseWhenHealthy(): void
    {
        $this->assertFalse($this->io->isSocketReady());
    }

    public function testIsSocketReadyReturnsTrueAfterPeerClose(): void
    {
        fclose($this->accepted);
        $this->accepted = null;

        $this->assertTrue($this->io->isSocketReady());
    }

    public function testIsSocketReadyThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->isSocketReady();
    }

    public function testReadThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->read(1);
    }

    public function testStreamSetTimeoutThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->stream_set_timeout(5);
    }

    public function testWriteThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->write('data');
    }

    public function testStreamGetLineThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->stream_get_line(8);
    }

    public function testStreamGetContentsThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->stream_get_contents(8);
    }

    public function testSelectWriteThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->selectWrite(1, 0);
    }

    public function testSelectReadThrowsWhenSocketClosed(): void
    {
        $this->io->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active socket connection');

        $this->io->selectRead(1, 0);
    }

    protected function setUp(): void
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($this->server);
        stream_set_timeout($this->server, 2);
        $name       = stream_socket_get_name($this->server, false);
        $this->port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        $this->io = new StreamIO('127.0.0.1', $this->port, 1, 1);
        $this->accepted = stream_socket_accept($this->server, 2);
        $this->assertNotFalse($this->accepted);
    }

    protected function tearDown(): void
    {
        if (isset($this->io)) {
            $this->io->close();
        }
        if (is_resource($this->accepted)) {
            fclose($this->accepted);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }
}
