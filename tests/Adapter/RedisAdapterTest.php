<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Redis;
use BackQ\Adapter\Redis\RedisConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    /**
     * Where the adapter's worker leases live, before the connection applies its prefix
     *
     * Spelled out here rather than read from the adapter: the point of the assertion is that
     * the name the adapter writes is the name this expects, so a change to the constant has to
     * fail a test rather than be followed by one.
     */
    private const string WORKERS_KEY = 'backq:workers:';

    public function testPublisherToConsumerFlow(): void
    {
        [$host, $port] = $this->reachableServer();

        $queue = 'backq.test.' . uniqid();
        $body  = 'hello-from-tests-' . uniqid();

        $publisher = new Redis(new NullLogger(), new RedisConfig(host: $host, port: $port));
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite($queue));
        $taskId = $publisher->putTask($body);
        $this->assertNotFalse($taskId);
        $publisher->disconnect();

        $consumer = new Redis(new NullLogger(), new RedisConfig(host: $host, port: $port));
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

    /**
     * The feature is only worth having because the answer spans processes: a publisher
     * answers from the leases, not from anything it can see in its own process. Two
     * adapters in one PHP process are not that — they share a Redis, which is the thing
     * under test.
     */
    public function testHasWorkersSeesAWorkerInAnotherProcess(): void
    {
        [$host, $port] = $this->reachableServer();

        $queue  = 'backq.test.' . uniqid();
        $config = new RedisConfig(host: $host, port: $port);

        $worker = new Redis(new NullLogger(), $config);
        $this->assertTrue($worker->connect());
        $this->assertTrue($worker->bindRead($queue));

        /**
         * A second connection to the same server, the way a publisher in a web request has
         * one: it has never bound read, so the only thing that can answer it is the registry
         * the worker wrote.
         */
        $publisher = new Redis(new NullLogger(), $config);
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite($queue));

        try {
            $this->assertTrue($publisher->hasWorkers($queue));

            $worker->disconnect();

            $this->assertFalse($publisher->hasWorkers($queue), 'a released lease must not be reported');
        } finally {
            $this->dropRegistryKey($host, $port, self::WORKERS_KEY . $queue);
        }
    }

    /**
     * The key is where the adapter says it is. phpredis applies the configured prefix, so a
     * change in illuminate or phpredis here is invisible until the feature silently answers
     * false forever — and this is the only assertion that can see it.
     */
    public function testTheLeaseIsWrittenUnderThePrefixedKeyNameOnly(): void
    {
        [$host, $port] = $this->reachableServer();

        $queue  = 'backq.test.' . uniqid();
        $prefix = 'backqtest:';
        $config = new RedisConfig(host: $host, port: $port, prefix: $prefix);

        $worker = new Redis(new NullLogger(), $config);
        $this->assertTrue($worker->connect());
        $this->assertTrue($worker->bindRead($queue));

        $raw = $this->rawClient($host, $port);
        $key = self::WORKERS_KEY . $queue;

        try {
            $this->assertCount(1, $raw->zRange($prefix . $key, 0, -1));
            $this->assertSame(0, $raw->zCard($key), 'the unprefixed name must stay empty');
        } finally {
            $raw->del([$prefix . $key, $key]);
            $worker->disconnect();
        }
    }

    /**
     * The SIGKILL case, which two adapters in one process cannot produce: disconnect() always
     * runs there. A lease left behind by a worker that is gone has to be reaped, or every
     * publisher reports workers for a queue nobody is consuming.
     */
    public function testAStaleLeaseIsReapedAndStopsBeingReported(): void
    {
        [$host, $port] = $this->reachableServer();

        $queue  = 'backq.test.' . uniqid();
        $config = new RedisConfig(host: $host, port: $port);
        $key    = self::WORKERS_KEY . $queue;

        $raw = $this->rawClient($host, $port);
        $raw->zAdd($key, $this->serverSeconds($raw) - 1, 'a-worker-that-was-killed');

        $publisher = new Redis(new NullLogger(), $config);

        try {
            $this->assertTrue($publisher->connect());
            $this->assertTrue($publisher->bindWrite($queue));

            $this->assertFalse($publisher->hasWorkers($queue), 'an expired lease is not a worker');
            $this->assertSame(0, $raw->zCard($key), 'the expired lease is gone, not just uncounted');
        } finally {
            $raw->del([$key]);
            $publisher->disconnect();
        }
    }

    /**
     * The one place the adapter's own words go, without the prefix the adapter configured
     */
    private function rawClient(string $host, int $port): \Redis
    {
        $raw = new \Redis();
        $raw->connect($host, $port);

        return $raw;
    }

    /**
     * The lease score is compared against the server's clock, so the test writes one the same
     * way rather than against this host's
     */
    private function serverSeconds(\Redis $raw): int
    {
        $time = $raw->time();
        $this->assertIsArray($time);

        return (int) $time[0];
    }

    /**
     * @return array{0: string, 1: int} the host and port the tests talk to
     */
    private function reachableServer(): array
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

        return [$host, $port];
    }

    /**
     * @param list<string> $keys
     */
    private function dropRegistryKey(string $host, int $port, string ...$keys): void
    {
        $raw = $this->rawClient($host, $port);
        $raw->del($keys);
    }
}
