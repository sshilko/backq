<?php

namespace BackQ\Tests\Support;

use RuntimeException;

use function fclose;
use function fread;
use function fwrite;
use function implode;
use function is_resource;
use function sprintf;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function strlen;
use function substr;
use function usleep;

/**
 * Minimal loopback beanstalkd protocol responder used by unit tests.
 *
 * It accepts a single client connection and lets the test queue protocol
 * responses ahead of the client read, then lets the test inspect the
 * requests the client wrote. No real beanstalkd process is required.
 */
final class FakeBeanstalkServer
{
    private const READ_RETRIES = 200;

    private const READ_RETRY_SLEEP_US = 10000;

    private $server;

    private $accepted;

    private int $port;

    public function __construct()
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $this->server) {
            throw new RuntimeException(sprintf('Failed to bind test server: %s (%d)', $errstr, $errno));
        }
        $name       = stream_socket_get_name($this->server, false);
        $this->port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function accept(): void
    {
        $this->accepted = stream_socket_accept($this->server, 5);
        if (false === $this->accepted) {
            throw new RuntimeException('Failed to accept client connection');
        }
    }

    /**
     * Queues a raw protocol response for the connected client.
     */
    public function queueResponse(string $response): int
    {
        $written = fwrite($this->accepted, $response);
        if (false === $written) {
            throw new RuntimeException('Failed to write response to client');
        }

        return $written;
    }

    /**
     * Queues an `OK <len>\r\n<yaml>\r\n` stats response from associative data.
     *
     * The response body must not end with a trailing newline so it matches
     * what a real beanstalkd sends, i.e. what the client decoder expects.
     *
     * @param array<string, int|string> $data
     */
    public function queueStats(array $data): void
    {
        $lines = ['---'];
        foreach ($data as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        $yaml = implode("\n", $lines);
        $this->queueResponse('OK ' . strlen($yaml) . "\r\n" . $yaml . "\r\n");
    }

    /**
     * Reads back the request the client wrote, retrying until `$length`
     * bytes arrived or a timeout is reached.
     *
     * @return string|false `false` when the peer closed the connection.
     */
    public function readRequest(int $length): string|false
    {
        $data = '';
        for ($i = 0; $i < self::READ_RETRIES && strlen($data) < $length; $i++) {
            $chunk = fread($this->accepted, $length - strlen($data));
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                usleep(self::READ_RETRY_SLEEP_US);
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->accepted)) {
            fclose($this->accepted);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }
}
