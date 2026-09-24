<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\ConnectionState;
use BackQ\Adapter\Nsq;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use function dirname;
use function fclose;
use function fgets;
use function is_resource;
use function json_decode;
use function json_encode;
use function microtime;
use function pack;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function restore_error_handler;
use function set_error_handler;
use function str_pad;
use function str_starts_with;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Unit tests for the Nsq adapter that do not require a running nsqd.
 *
 * State is injected through reflection to exercise the protocol checks,
 * guard clauses and validation logic without any socket.
 */
class NsqAdapterCoreTest extends TestCase
{
    private const string TEST_HOST = '127.0.0.1';

    private const int TEST_PORT = 4150;

    public function testConstructAppliesConfig(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);

        $config = (new ReflectionProperty(Nsq::class, 'config'))->getValue($nsq);
        $this->assertSame(self::TEST_HOST, $config['host']);
        $this->assertSame(self::TEST_PORT, $config['port']);
        $this->assertNotEmpty($config['clientId']);
    }

    public function testSetWorkTimeoutUpdatesHeartbeatConfig(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);

        $nsq->setWorkTimeout(6);

        $config = (new ReflectionProperty(Nsq::class, 'config'))->getValue($nsq);
        $this->assertSame(6000, $config['heartbeat_interval_ms']);
    }

    public function testSetWorkTimeoutIgnoresSubsecondValues(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);

        $nsq->setWorkTimeout(0);

        $config = (new ReflectionProperty(Nsq::class, 'config'))->getValue($nsq);
        $this->assertSame(5000, $config['heartbeat_interval_ms']);
    }

    public function testDisconnectReturnsFalseWhenNotConnected(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->assertFalse($nsq->disconnect());
    }

    public function testPingReturnsFalseWhenNotConnected(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->assertFalse($nsq->ping());
    }

    public function testBindWriteRequiresConnectedClient(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->assertFalse($nsq->bindWrite('queue'));
    }

    public function testBindReadRequiresConnectedClient(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->assertFalse($nsq->bindRead('queue'));
    }

    public function testBindWriteEntersBindWriteState(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::Nothing);

        $this->assertTrue($nsq->bindWrite('queue'));

        $this->assertSame(ConnectionState::BindWrite, (new ReflectionProperty(Nsq::class, 'state'))->getValue($nsq));
    }

    public function testBindWriteRejectedWhenAlreadyBound(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->assertFalse($nsq->bindWrite('queue'));
    }

    public function testPutTaskRequiresBindWriteState(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindRead);

        $this->assertFalse($nsq->putTask('body'));
    }

    public function testPutTaskRejectsTooLargeTtr(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('msg_timeout');

        $nsq->putTask('body', [Nsq::PARAM_JOBTTR => Nsq::JOBTTR_DEFAULT + 1]);
    }

    public function testPutTaskRejectsTooLargeDelay(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT, ['max_req_timeout' => 60]);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('max_req_timeout');

        $nsq->putTask('body', [Nsq::PARAM_READYWAIT => 61]);
    }

    public function testPickTaskReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->assertFalse($nsq->pickTask());
    }

    public function testAfterWorkSuccessReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->assertFalse($nsq->afterWorkSuccess(0));
    }

    public function testAfterWorkFailedReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->assertFalse($nsq->afterWorkFailed(1));
    }

    public function testHasWorkersReportsNotSupported(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $nsq->setTriggerErrorOnError(false);

        $this->assertFalse($nsq->hasWorkers('queue'));
    }

    public function testFrameMessagePayloadIsParsedFromFrame(): void
    {
        $messageFrame = $this->buildMessageFrame('the-payload');
        $message      = substr($messageFrame, 26);
        $msgId        = substr($messageFrame, 10, 16);

        $this->assertSame('the-payload', $message);
        $this->assertSame(16, strlen($msgId));
    }

    public function testIdentifyPayloadIsJson(): void
    {
        $identify = json_decode($this->buildIdentifyJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($identify['feature_negotiation']);
        $this->assertSame('BackQ\Nsq', $identify['user_agent']);
        $this->assertSame(5000, $identify['heartbeat_interval']);
    }

    public function testPickTaskReturnsFalseOnHeartbeatFrame(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('heartbeat');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());

            /**
             * A heartbeat frame acts as a break/timeout in the pickTask cycle
             * and must not be surfaced as a job with an empty id
             */
            $this->assertTrue($nsq->bindRead('heartbeat-queue'));
            $this->assertFalse($nsq->pickTask());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testPickTaskParsesMessageFrame(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('message');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->bindRead('message-queue'));

            $task = $nsq->pickTask();

            $this->assertIsArray($task);
            [$id, $message, $meta] = $task;
            $this->assertSame('msgid01234567890', $id);
            $this->assertSame('the-payload', $message);
            $this->assertSame(1, $meta['attempts']);
            $this->assertIsString($meta['time']);
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testPingReportsSocketHealthWhenConnected(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('idle');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertFalse($nsq->ping());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testAfterWorkFailedRequeuesMessage(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('requeue');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->bindRead('requeue-topic'));
            $this->assertTrue($nsq->afterWorkFailed('msgid01234567890'));
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testPickTaskLogsDeprecatedTimeoutAndReturnsMessage(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('message');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->bindRead('message-queue'));

            $task = $nsq->pickTask(3);

            $this->assertIsArray($task);
            $this->assertSame('the-payload', $task[1]);
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testPickTaskThrowsOnNonMessageFrame(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('non-message');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->bindRead('non-message-topic'));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was expecting a message frame');

            $nsq->pickTask();
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testPutTaskRejectsTtrBeyondHeartbeatRatio(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, ConnectionState::BindWrite);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('heartbeat');

        $nsq->putTask('body', [Nsq::PARAM_JOBTTR => 8]);
    }

    public function testPutTaskWritesDelayedPublishFromReadyWait(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('pubdelay');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->bindWrite('pubdelay-topic'));
            $this->assertTrue($nsq->putTask('payload', [Nsq::PARAM_READYWAIT => 5]));
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectReturnsFalseWhenPeerIsDown(): void
    {
        $temp        = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($temp);
        $name        = stream_socket_get_name($temp, false);
        $tempPort    = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        fclose($temp);

        $nsq = new Nsq(self::TEST_HOST, $tempPort);
        $nsq->setTriggerErrorOnError(false);

        $this->assertFalse($nsq->connect());
    }

    public function testConnectTwiceRebindsConnection(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('multi');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenIdentifyReplyIsNotAResponse(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('identify-error');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenFeatureListIsNotAnArray(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('identify-null');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenAuthRequiredButMissing(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('identify-auth');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectAuthenticatesWhenAuthProvided(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('auth-ok');
        $nsq = new Nsq(self::TEST_HOST, $port, ['auth' => 'secret']);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
            $this->assertNotEmpty((new ReflectionProperty(Nsq::class, 'authentication'))->getValue($nsq));
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenAuthReplyIsNotAResponse(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('auth-error');
        $nsq = new Nsq(self::TEST_HOST, $port, ['auth' => 'secret']);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenAuthReplyIsNotJson(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('auth-badjson');
        $nsq = new Nsq(self::TEST_HOST, $port, ['auth' => 'secret']);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testBindReadThrowsOnUnexpectedSubscribeResponse(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('sub-bad');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('expecting Success');

            $nsq->bindRead('sub-bad-topic');
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testIdentifyHeartbeatIsRepliedWithNoop(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('identify-heartbeat');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testConnectThrowsWhenFrameIsTruncated(): void
    {
        [$process, $pipes, $port] = $this->startFakeServer('short-frame');
        $nsq = new Nsq(self::TEST_HOST, $port);
        $nsq->setTriggerErrorOnError(false);

        try {
            $this->assertTrue($nsq->connect());
        } finally {
            $nsq->disconnect();
            $this->stopFakeServer($process, $pipes);
        }
    }

    public function testWriteIdentifyThrowsWhenAlreadyBound(): void
    {
        $nsq    = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, false, ConnectionState::BindWrite);
        $method = new ReflectionMethod(Nsq::class, 'writeIdentify');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incorrect protocol usage while writeIdentify');

        $method->invoke($nsq);
    }

    public function testWriteReadyReturnsFalseWhenNotSubscribed(): void
    {
        $nsq    = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $method = new ReflectionMethod(Nsq::class, 'writeReady');

        $this->assertFalse($method->invoke($nsq, 1));
    }

    public function testUnpackFieldThrowsOnUndecodableData(): void
    {
        $nsq    = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $method = new ReflectionMethod(Nsq::class, 'unpackField');

        set_error_handler(static function (int $severity, string $message): bool {
            return true;
        });
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to unpack frame data');

            $method->invoke($nsq, 'N', 'ab');
        } finally {
            restore_error_handler();
        }
    }

    private function setState(Nsq $nsq, bool $connected, ConnectionState $state, array $stateData = []): void
    {
        (new ReflectionProperty(Nsq::class, 'connected'))->setValue($nsq, $connected);
        (new ReflectionProperty(Nsq::class, 'state'))->setValue($nsq, $state);
        (new ReflectionProperty(Nsq::class, 'stateData'))->setValue($nsq, $stateData);
    }

    /**
     * @return array{0:resource,1:array{0:resource,1:resource,2:resource},2:int}
     */
    private function startFakeServer(string $mode): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/FakeNsqdServer.php', $mode],
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Unable to start the fake nsqd server');
        }

        $port     = null;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $line = fgets($pipes[1]);
            if (false === $line) {
                break;
            }
            $line = trim($line);
            if (str_starts_with($line, 'PORT=')) {
                $port = (int) substr($line, 5);

                break;
            }
        }

        if (null === $port) {
            $this->stopFakeServer($process, $pipes);
            $this->fail('The fake nsqd server did not report a port');
        }

        return [$process, $pipes, $port];
    }

    /**
     * @param resource $process
     * @param array{0:resource,1:resource,2:resource} $pipes
     */
    private function stopFakeServer($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function buildMessageFrame(string $payload): string
    {
        return pack('J', 1620000000 * 1000000000) .
            pack('n', 1) .
            str_pad('msgid', 16, '0') .
            $payload;
    }

    private function buildIdentifyJson(): string
    {
        return json_encode([
            'feature_negotiation' => true,
            'heartbeat_interval' => 5000,
            'msg_timeout' => Nsq::JOBTTR_DEFAULT * 1000,
            'user_agent' => 'BackQ\Nsq',
        ], JSON_THROW_ON_ERROR);
    }
}
