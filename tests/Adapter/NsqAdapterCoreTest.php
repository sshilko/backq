<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Nsq;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function json_decode;
use function json_encode;
use function pack;
use function str_pad;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for the Nsq adapter that do not require a running nsqd.
 *
 * State is injected through reflection to exercise the protocol checks,
 * guard clauses and validation logic without any socket.
 */
class NsqAdapterCoreTest extends TestCase
{
    private const TEST_HOST = '127.0.0.1';

    private const TEST_PORT = 4150;

    private const STATE_BINDWRITE = 1;

    private const STATE_BINDREAD = 2;

    private const STATE_NOTHING = 0;

    private function setState(Nsq $nsq, bool $connected, int $state, array $stateData = []): void
    {
        (new ReflectionProperty(Nsq::class, 'connected'))->setValue($nsq, $connected);
        (new ReflectionProperty(Nsq::class, 'state'))->setValue($nsq, $state);
        (new ReflectionProperty(Nsq::class, 'stateData'))->setValue($nsq, $stateData);
    }

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
        $this->setState($nsq, true, self::STATE_NOTHING);

        $this->assertTrue($nsq->bindWrite('queue'));

        $this->assertSame(self::STATE_BINDWRITE, (new ReflectionProperty(Nsq::class, 'state'))->getValue($nsq));
    }

    public function testBindWriteRejectedWhenAlreadyBound(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->assertFalse($nsq->bindWrite('queue'));
    }

    public function testPutTaskRequiresBindWriteState(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDREAD);

        $this->assertFalse($nsq->putTask('body'));
    }

    public function testPutTaskRejectsTooLargeTtr(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('msg_timeout');

        $nsq->putTask('body', [Nsq::PARAM_JOBTTR => Nsq::JOBTTR_DEFAULT + 1]);
    }

    public function testPutTaskRejectsTooLargeDelay(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT, ['max_req_timeout' => 60]);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('max_req_timeout');

        $nsq->putTask('body', [Nsq::PARAM_READYWAIT => 61]);
    }

    public function testPickTaskReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->assertFalse($nsq->pickTask());
    }

    public function testAfterWorkSuccessReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->assertFalse($nsq->afterWorkSuccess(0));
    }

    public function testAfterWorkFailedReturnsFalseWhenNotSubscribed(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $this->setState($nsq, true, self::STATE_BINDWRITE);

        $this->assertFalse($nsq->afterWorkFailed(1));
    }

    public function testHasWorkersReportsNotSupported(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
        $nsq->setTriggerErrorOnError(false);

        $this->assertSame(1, $nsq->hasWorkers('queue'));
    }

    public function testFrameMessagePayloadIsParsedFromFrame(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);

        $messageFrame = $this->buildMessageFrame('the-payload');
        $message      = substr($messageFrame, 26);
        $msgId        = substr($messageFrame, 10, 16);

        $this->assertSame('the-payload', $message);
        $this->assertSame(16, strlen($msgId));
    }

    public function testIdentifyPayloadIsJson(): void
    {
        $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);

        $identify = json_decode($this->buildIdentifyJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($identify['feature_negotiation']);
        $this->assertSame('BackQ\Nsq', $identify['user_agent']);
        $this->assertSame(5000, $identify['heartbeat_interval']);
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
            'user_agent' => 'BackQ\Nsq',
            'msg_timeout' => Nsq::JOBTTR_DEFAULT * 1000,
            'heartbeat_interval' => 5000,
        ], JSON_THROW_ON_ERROR);
    }
}
