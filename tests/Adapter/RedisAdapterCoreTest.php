<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\ConnectionState;
use BackQ\Adapter\Redis;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for the Redis adapter that do not require a running Redis server.
 *
 * State and connection flags are injected through reflection to exercise the
 * guard clauses, state machine and work-timeout logic without any socket.
 */
class RedisAdapterCoreTest extends TestCase
{
    public function testConstructAppliesConfig(): void
    {
        $redis = new Redis('redis.example', 6380);

        $this->assertSame('redis.example', (new ReflectionProperty(Redis::class, 'host'))->getValue($redis));
        $this->assertSame(6380, (new ReflectionProperty(Redis::class, 'port'))->getValue($redis));
    }

    public function testNewInstanceStartsDisconnected(): void
    {
        $redis = new Redis();

        $this->assertFalse($redis->ping());
        $this->assertFalse($redis->disconnect());
    }

    public function testConnectMarksConnected(): void
    {
        $redis = new Redis();

        $this->assertTrue($redis->connect());
        $this->assertTrue((new ReflectionProperty(Redis::class, 'connected'))->getValue($redis));
    }

    public function testBindReadRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->bindRead('queue'));
    }

    public function testBindWriteRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->bindWrite('queue'));
    }

    public function testBindReadRejectedWhenAlreadyBound(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);

        $this->assertFalse($redis->bindRead('queue'));
    }

    public function testBindWriteRejectedWhenAlreadyBound(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindWrite);

        $this->assertFalse($redis->bindWrite('queue'));
    }

    public function testPutTaskRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->putTask('body'));
    }

    public function testPickTaskRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->pickTask(1));
    }

    public function testAfterWorkSuccessRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->afterWorkSuccess('job-1'));
    }

    public function testAfterWorkFailedRequiresConnectedClient(): void
    {
        $redis = new Redis();
        $this->assertFalse($redis->afterWorkFailed('job-1'));
    }

    public function testHasWorkersReportsNotSupported(): void
    {
        $redis = new Redis();
        $redis->setTriggerErrorOnError(false);

        $this->assertFalse($redis->hasWorkers('queue'));
    }

    public function testSetWorkTimeoutStoresSubReadTimeoutValue(): void
    {
        $redis = new Redis();
        $redis->setTriggerErrorOnError(false);

        $redis->setWorkTimeout(5);

        $this->assertSame(5, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    public function testSetWorkTimeoutClampsBeyondReadTimeout(): void
    {
        $redis = new Redis();
        $redis->setTriggerErrorOnError(false);

        $redis->setWorkTimeout(15);

        $this->assertSame(9, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    private function setState(Redis $redis, bool $connected, ConnectionState $state): void
    {
        (new ReflectionProperty(Redis::class, 'connected'))->setValue($redis, $connected);
        (new ReflectionProperty(Redis::class, 'state'))->setValue($redis, $state);
    }
}
