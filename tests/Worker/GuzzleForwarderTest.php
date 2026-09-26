<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\GuzzleForwarder as MessageGuzzleForwarder;
use BackQ\Tests\Support\NotReadyGuzzleForwarderMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestGuzzleForwarderWorker;
use GuzzleHttp\Psr7\Request;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use function array_column;
use function fclose;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_array;
use function pcntl_fork;
use function pcntl_waitpid;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function str_ends_with;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;
use const E_USER_WARNING;

class GuzzleForwarderTest extends TestCase
{
    public function testAnEmptyQueueIsLeftAlone(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = false;

        $worker = $this->worker($adapter);
        $worker->setWorkTimeout(4);
        $worker->run();

        $this->assertContains('disconnect', $adapter->calls);
        $this->assertNothingWasAcknowledged($adapter);
    }

    public function testAPayloadWithoutAWorkTimeoutIsAcknowledgedWithoutSending(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = false;

        $logger = new RecordingLogger();
        $worker = $this->worker($adapter, $logger);
        $worker->setWorkTimeout(0);
        $worker->run();

        $this->assertStringContainsString('Worker does not support payload of: NULL', $this->log($logger));
        $this->assertNothingWasAcknowledged($adapter);
    }

    public function testAnUnsupportedPayloadIsAcknowledged(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [21, 'not-a-forwarder-message'];

        $worker = $this->worker($adapter);
        $worker->run();

        $this->assertContains(['afterWorkSuccess', 21], $adapter->calls);
    }

    public function testANonStringPayloadIsAcknowledged(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [22, 12345];

        $worker = $this->worker($adapter);
        $worker->run();

        $this->assertContains(['afterWorkSuccess', 22], $adapter->calls);
    }

    public function testAMessageThatIsNotReadyYetIsRescheduled(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [23, serialize(
            new NotReadyGuzzleForwarderMessage(new Request('GET', 'http://127.0.0.1:9/'))
        )];

        $worker = $this->worker($adapter);
        $worker->run();

        $this->assertContains(['afterWorkFailed', 23], $adapter->calls);
        $this->assertNotContainsSuccessfulAck($adapter);
    }

    public function testAnExpiredMessageIsDroppedAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [24, serialize(
            new MessageGuzzleForwarder(new Request('GET', 'http://127.0.0.1:9/'), 5, null, time() - 1)
        )];

        $worker = $this->worker($adapter);
        $worker->run();

        $this->assertContains(['afterWorkSuccess', 24], $adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 24], $adapter->calls);
    }

    public function testTheRequestIsForwardedAndTheCallbackSeesTheResponse(): void
    {
        [$port, $pid, $server] = $this->serveOneRequest(
            "HTTP/1.1 201 Created\r\nX-Token: abc\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
        );

        /**
         * The callback travels through the queue, so it reports back through a
         * file: a serialized closure copies everything it closed over
         */
        $report  = tempnam(sys_get_temp_dir(), 'backqcb_');
        $message = new MessageGuzzleForwarder(
            new Request('GET', 'http://127.0.0.1:' . $port . '/forwarded'),
            5,
            new SerializableClosure(
                static function (ResponseInterface $response) use ($report): void {
                    file_put_contents(
                        $report,
                        $response->getStatusCode() . ' ' . $response->getHeaderLine('X-Token')
                    );
                }
            )
        );

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [25, serialize($message)];

            $logger = new RecordingLogger();
            $worker = $this->worker($adapter, $logger);
            $worker->run();
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }

        $this->assertSame('201 abc', file_get_contents($report), 'the callback has to see the forwarded response');
        unlink($report);

        $this->assertStringContainsString('Request sent, got response 201', $this->log($logger));
        $this->assertContains(['afterWorkSuccess', 25], $adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 25], $adapter->calls);
    }

    public function testAServerErrorIsLogged(): void
    {
        [$port, $pid, $server] = $this->serveOneRequest(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
        );

        $message = new MessageGuzzleForwarder(new Request('GET', 'http://127.0.0.1:' . $port . '/broken'), 5);

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [26, serialize($message)];

            $logger = new RecordingLogger();
            $worker = $this->worker($adapter, $logger);
            $worker->run();
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }

        $this->assertStringContainsString('Request sent, FAILED with', $this->log($logger));
        $this->assertContains(['afterWorkSuccess', 26], $adapter->calls);
    }

    public function testARefusedConnectionIsLoggedAsAFailedRequest(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [27, serialize(
            new MessageGuzzleForwarder(new Request('GET', 'http://127.0.0.1:9/'), 5)
        )];

        $logger = new RecordingLogger();
        $worker = $this->worker($adapter, $logger);
        $worker->run();

        $this->assertStringContainsString('Request sent, FAILED with', $this->log($logger));

        /**
         * The job is reported as processed even though the request never left:
         * the worker has no way to tell a failed request from a delivered one
         */
        $this->assertContains(['afterWorkSuccess', 27], $adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 27], $adapter->calls);
    }

    public function testAnUnreadableRequestIsReportedAsAPhpWarning(): void
    {
        $message = new MessageGuzzleForwarder(new Request('GET', 'http://127.0.0.1:9/'), 5);
        (new ReflectionProperty(MessageGuzzleForwarder::class, 'request'))->setValue($message, 'not a request');

        $warnings = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_USER_WARNING
        );

        try {
            $adapter                = new TestAdapter();
            $adapter->pickTaskResult = [29, serialize($message)];

            $worker = $this->worker($adapter);
            $worker->run();
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('GuzzleForwarder worker exception', implode("\n", $warnings));
        $this->assertContains(['afterWorkSuccess', 29], $adapter->calls);
    }

    public function testAFailedAckStopsTheWorker(): void
    {
        $adapter                        = new TestAdapter();
        $adapter->pickTaskResult         = [28, 'garbage'];
        $adapter->afterWorkSuccessResult = false;

        $logger = new RecordingLogger();
        $worker = $this->worker($adapter, $logger);
        $worker->run();

        $this->assertStringContainsString('EXCEPTION: Worker failed to acknowledge job result', $this->log($logger));
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testAnAdapterThatCannotConnectIsNeverAskedForWork(): void
    {
        $adapter               = new TestAdapter();
        $adapter->connectResult = false;

        $worker = $this->worker($adapter);
        $worker->run();

        $this->assertNotContains('pickTask', $adapter->calls);
        $this->assertNotContains(['bindRead', 'guzzle_queue'], $adapter->calls);
        $this->assertNotContains('disconnect', $adapter->calls);
    }

    private function worker(TestAdapter $adapter, ?RecordingLogger $logger = null): TestGuzzleForwarderWorker
    {
        $worker = new TestGuzzleForwarderWorker($adapter);
        $worker->setLogger($logger ?? new NullLogger());
        $worker->setRestartThreshold(1);

        return $worker;
    }

    private function log(RecordingLogger $logger): string
    {
        return implode("\n", array_column($logger->records, 1));
    }

    /**
     * Answer exactly one HTTP request in a forked process
     *
     * @return array{0: int, 1: int, 2: resource} the port, the pid of the responder, the server
     */
    private function serveOneRequest(string $response): array
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
                fwrite($connection, $response);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        return [$port, $pid, $server];
    }

    private function assertNotContainsSuccessfulAck(TestAdapter $adapter): void
    {
        foreach ($adapter->calls as $call) {
            if (is_array($call) && 'afterWorkSuccess' === $call[0]) {
                $this->fail('unexpected afterWorkSuccess');
            }
        }
    }

    private function assertNothingWasAcknowledged(TestAdapter $adapter): void
    {
        foreach ($adapter->calls as $call) {
            if (is_array($call) && 'afterWorkSuccess' === $call[0]) {
                $this->fail('unexpected afterWorkSuccess');
            }
            if (is_array($call) && 'afterWorkFailed' === $call[0]) {
                $this->fail('unexpected afterWorkFailed');
            }
        }
    }
}
