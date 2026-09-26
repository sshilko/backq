<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Guzzle as GuzzleMessage;
use BackQ\Tests\Support\GuzzleExpiredMessage;
use BackQ\Tests\Support\GuzzleNotReadyMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Guzzle;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use function array_column;
use function fclose;
use function fgets;
use function file_exists;
use function file_get_contents;
use function fwrite;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function pcntl_fork;
use function pcntl_waitpid;
use function serialize;
use function str_ends_with;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function tempnam;
use function unlink;

class GuzzleWorkerTest extends TestCase
{
    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [11, 'not-a-guzzle-message'];

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 11], $adapter->calls);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testEmptyPayloadIsSkipped(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = false;

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertSame(
            [
                ['setWorkTimeout', 4],
                'connect',
                ['bindRead', 'guzzle'],
                ['pickTask', null],
                'disconnect',
            ],
            $adapter->calls
        );
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [12, 12345];

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 12], $adapter->calls);
    }

    public function testDefersNotReadyMessage(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [13, serialize(
            new GuzzleNotReadyMessage(new Request('GET', 'http://127.0.0.1:9/'))
        )];
        $adapter->afterWorkFailedResult = true;

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkFailed', 13], $adapter->calls);
        $this->assertNotContainsSuccessfulAck($adapter);
    }

    public function testDiscardsExpiredMessageAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [14, serialize(new GuzzleExpiredMessage(new Request('GET', 'http://127.0.0.1:9/')))];
        $adapter->afterWorkFailedResult = true;

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 14], $adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 14], $adapter->calls);
    }

    public function testSendsAsyncRequestToLocalServer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'unable to open local socket server: ' . $errstr);
        $socketName = stream_socket_get_name($server, false);
        $port       = (int) substr($socketName, strrpos($socketName, ':') + 1);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed');
        if (0 === $pid) {
            $connection = stream_socket_accept($server, 30);
            if (false !== $connection) {
                $buffer = '';
                while (false !== ($line = fgets($connection))) {
                    $buffer .= $line;
                    if (str_ends_with($buffer, "\r\n\r\n")) {
                        break;
                    }
                }
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [15, serialize(
                new GuzzleMessage(new Request('GET', 'http://127.0.0.1:' . $port . '/'))
            )];

            $logger = new RecordingLogger();
            $worker = new Guzzle($adapter);
            $worker->setLogger($logger);
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
            ini_set('error_log', $previous);
        }

        $wholeLog      = implode("\n", array_column($logger->records, 1));
        $loggedErrors  = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('got response 200 ', $wholeLog);
        $this->assertStringNotContainsString('Error while sending FCM', $loggedErrors);
        $this->assertContains(['afterWorkSuccess', 15], $adapter->calls);
    }

    public function testLogsServerErrorRejection(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'unable to open local socket server: ' . $errstr);
        $socketName = stream_socket_get_name($server, false);
        $port       = (int) substr($socketName, strrpos($socketName, ':') + 1);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed');
        if (0 === $pid) {
            $connection = stream_socket_accept($server, 30);
            if (false !== $connection) {
                $buffer = '';
                while (false !== ($line = fgets($connection))) {
                    $buffer .= $line;
                    if (str_ends_with($buffer, "\r\n\r\n")) {
                        break;
                    }
                }
                fwrite(
                    $connection,
                    "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
                );
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [18, serialize(
                new GuzzleMessage(new Request('GET', 'http://127.0.0.1:' . $port . '/'))
            )];

            $logger = new RecordingLogger();
            $worker = new Guzzle($adapter);
            $worker->setLogger($logger);
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Request sent, FAILED with', $wholeLog);
        $this->assertContains(['afterWorkSuccess', 18], $adapter->calls);
    }

    public function testLogsConnectRefusedFailure(): void
    {
        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [16, serialize(new GuzzleMessage(new Request('GET', 'http://127.0.0.1:9/')))];

            $worker = new Guzzle($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertSame(
            '',
            $loggedErrors,
            'A refused connection must be handled by the worker, not reported as a PHP error'
        );
        $this->assertContains(['afterWorkFailed', 16], $adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 16], $adapter->calls);
    }

    public function testAckFailureTriggersOuterCatch(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [17, 'garbage'];
        $adapter->afterWorkSuccessResult = false;

        $logger = new RecordingLogger();
        $worker = new Guzzle($adapter);
        $worker->setLogger($logger);
        $worker->setRestartThreshold(1);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('EXCEPTION: Worker failed to acknowledge job result', $wholeLog);
        $this->assertContains('disconnect', $adapter->calls);
    }

    /**
     * @param TestAdapter $adapter
     */
    private function assertNotContainsSuccessfulAck(TestAdapter $adapter): void
    {
        foreach ($adapter->calls as $call) {
            if (is_array($call) && 'afterWorkSuccess' === $call[0]) {
                $this->fail('unexpected afterWorkSuccess in ' . var_export($adapter->calls, true));
            }
        }
    }
}
