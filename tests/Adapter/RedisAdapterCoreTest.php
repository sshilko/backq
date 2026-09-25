<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\ConnectionState;
use BackQ\Adapter\Redis;
use BackQ\Adapter\Redis\Manager;
use BackQ\Adapter\Redis\Queue;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Capsule\Manager as QueueManager;
use Illuminate\Queue\Jobs\RedisJob;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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

    public function testSetWorkTimeoutFallsBackToSecondWhenReadTimeoutTooSmall(): void
    {
        $redis = new Redis('127.0.0.1', 6379, false, null, null, 10, 1);
        $redis->setTriggerErrorOnError(false);

        $redis->setWorkTimeout(15);

        $this->assertSame(1, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    public function testRetryJobAfterStoresRetryAfter(): void
    {
        $redis = new Redis();

        $redis->retryJobAfter(90);

        $this->assertSame(90, (new ReflectionProperty(Redis::class, 'retryAfter'))->getValue($redis));
    }

    public function testConnectWhenAlreadyConnectedRebinds(): void
    {
        $redis = new Redis();

        $this->assertTrue($redis->connect());
        $this->assertTrue($redis->connect());

        $this->assertTrue((new ReflectionProperty(Redis::class, 'connected'))->getValue($redis));
    }

    public function testExceptionHandlerResolvesAndReports(): void
    {
        $redis   = new Redis();
        $app     = (new ReflectionProperty(Redis::class, 'app'))->getValue($redis);
        $handler = $app->make('exception.handler');

        $this->assertInstanceOf(ExceptionHandler::class, $handler);

        set_error_handler(static function (int $severity, string $message): bool {
            return true;
        });
        try {
            $handler->report(new RuntimeException('boom'));
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(Response::class, $handler->render(null, new RuntimeException('boom')));
        $handler->renderForConsole(null, new RuntimeException('boom'));
        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
    }

    public function testPingReportsSuccessWhenQueueIsAlive(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);
        $this->wireManager($redis, $queue);

        $this->assertTrue($redis->ping());
    }

    public function testPingReturnsFalseWhenRedisThrows(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $redis->setTriggerErrorOnError(false);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willThrowException(new \RedisException('Lost connection'));

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);
        $this->wireManager($redis, $queue);

        $this->assertFalse($redis->ping());
    }

    public function testDisconnectReleasesReservedJobs(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $redis->setTriggerErrorOnError(false);

        $redisManager = $this->createMock(Manager::class);
        $redisManager->method('isConnected')->willReturn(true);

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisManager);
        $this->wireManager($redis, $queue);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('job-1');
        $job->expects($this->once())->method('release');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->assertTrue($redis->disconnect());
    }

    public function testDisconnectSurvivesQueueFailure(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $redis->setTriggerErrorOnError(false);

        $redisManager = $this->createMock(Manager::class);
        $redisManager->method('isConnected')->willThrowException(new RuntimeException('boom'));

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisManager);
        $this->wireManager($redis, $queue);

        $this->assertTrue($redis->disconnect());
    }

    public function testAfterWorkFailedReleasesReservedJob(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('job-1');
        $job->expects($this->once())->method('release');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->assertTrue($redis->afterWorkFailed('job-1'));
    }

    public function testAfterWorkFailedThrowsOnJobIdMismatch(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('other');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesnt match');

        $redis->afterWorkFailed('job-1');
    }

    public function testAfterWorkSuccessThrowsOnJobIdMismatch(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('other');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesnt match');

        $redis->afterWorkSuccess('job-1');
    }

    public function testPickTaskThrowsWhenReservedJobHasNoId(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $this->setQueueName($redis, 'the-queue');

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn(null);
        $job->expects($this->once())->method('release');

        $queue = $this->createMock(Queue::class);
        $queue->method('pop')->willReturn($job);
        $this->wireManager($redis, $queue);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reserved job without an id');

        $redis->pickTask();
    }

    public function testPickTaskThrowsWhenJobAlreadyReserved(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $this->setQueueName($redis, 'the-queue');

        $existing = $this->createMock(RedisJob::class);
        $existing->method('getJobId')->willReturn('job-1');
        $this->setReservedJobs($redis, ['job-1' => $existing]);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('job-1');
        $job->expects($this->once())->method('release');

        $queue = $this->createMock(Queue::class);
        $queue->method('pop')->willReturn($job);
        $this->wireManager($redis, $queue);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Already reserved job id');

        $redis->pickTask();
    }

    public function testPickTaskReturnsFalseWhenQueueIsEmpty(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindRead);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('pop')->willReturn(null);
        $this->wireManager($redis, $queue);

        $this->assertFalse($redis->pickTask());
    }

    public function testPutTaskPushesDelayedJob(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindWrite);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('later')->willReturn('delayed-1');
        $this->wireManager($redis, $queue);

        $this->assertSame('delayed-1', $redis->putTask('body', [Redis::PARAM_READYWAIT => 5]));
    }

    public function testPutTaskReturnsFalseWhenPushFails(): void
    {
        $redis = new Redis();
        $this->setState($redis, true, ConnectionState::BindWrite);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('push')->willReturn(null);
        $this->wireManager($redis, $queue);

        $this->assertFalse($redis->putTask('body'));
    }

    private function wireManager(Redis $redis, Queue $queue): void
    {
        $manager = $this->createMock(QueueManager::class);
        $manager->method('getConnection')->willReturn($queue);

        (new ReflectionProperty(Redis::class, 'queue'))->setValue($redis, $manager);
    }

    private function setQueueName(Redis $redis, string $queueName): void
    {
        (new ReflectionProperty(Redis::class, 'queueName'))->setValue($redis, $queueName);
    }

    /**
     * @param array<string, RedisJob> $jobs
     */
    private function setReservedJobs(Redis $redis, array $jobs): void
    {
        (new ReflectionProperty(Redis::class, 'reservedJobs'))->setValue($redis, $jobs);
    }

    private function setState(Redis $redis, bool $connected, ConnectionState $state): void
    {
        (new ReflectionProperty(Redis::class, 'connected'))->setValue($redis, $connected);
        (new ReflectionProperty(Redis::class, 'state'))->setValue($redis, $state);
    }
}
