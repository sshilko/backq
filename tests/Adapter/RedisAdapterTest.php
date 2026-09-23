<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Redis;
use PHPUnit\Framework\TestCase;
use function extension_loaded;
use function fclose;
use function fsockopen;
use function getenv;
use function is_array;
use function sprintf;
use function uniqid;

/**
 * Integration test against a real Redis server.
 *
 * Skips when ext-redis is missing or the server is unreachable, so the
 * plain host-side `composer app-tests` run stays green without docker.
 */
class RedisAdapterTest extends TestCase
{
    private const string DEFAULT_HOST = 'redis';

    private const int DEFAULT_PORT = 6379;

    public function testPublisherToConsumerFlow(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not available');
        }

        $host = getenv('BACKQ_REDIS_HOST') ?: self::DEFAULT_HOST;
        $port = (int) (getenv('BACKQ_REDIS_PORT') ?: self::DEFAULT_PORT);

        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (false === $sock) {
            self::markTestSkipped(sprintf('Redis not reachable at %s:%d', $host, $port));
        }
        fclose($sock);

        $queue = 'backq.test.' . uniqid();
        $body  = 'hello-from-tests-' . uniqid();

        $publisher = new Redis($host, $port);
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite($queue));
        $taskId = $publisher->putTask($body);
        $this->assertNotFalse($taskId);
        $publisher->disconnect();

        $consumer = new Redis($host, $port);
        $this->assertTrue($consumer->connect());
        $this->assertTrue($consumer->bindRead($queue));
        $task = $consumer->pickTask(2);

        if (is_array($task)) {
            [$id, $payload] = $task;
            $this->assertSame($body, $payload);
            $this->assertTrue($consumer->afterWorkSuccess($id));
        } else {
            $this->fail('Failed to pick published task from Redis');
        }

        $consumer->disconnect();
    }
}
