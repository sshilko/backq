<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\ConnectionState;
use BackQ\Adapter\Redis;
use BackQ\Adapter\Redis\Manager;
use BackQ\Adapter\Redis\Queue;
use BackQ\Adapter\Redis\RedisConfig;
use BackQ\Tests\Support\LogAssertions;
use BackQ\Tests\Support\RecordingLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Capsule\Manager as QueueManager;
use Illuminate\Queue\Jobs\RedisJob;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Unit tests for the Redis adapter that do not require a running Redis server.
 *
 * State and connection flags are injected through reflection to exercise the
 * guard clauses, state machine and work-timeout logic without any socket.
 */
class RedisAdapterCoreTest extends TestCase
{

    use LogAssertions;

    public function testConstructAppliesConfig(): void
    {
        $redis = new Redis(new NullLogger(), new RedisConfig(host: 'redis.example', port: 6380));

        $config = (new ReflectionProperty(Redis::class, 'config'))->getValue($redis);
        $this->assertInstanceOf(RedisConfig::class, $config);
        $this->assertSame('redis.example', $config->host);
        $this->assertSame(6380, $config->port);
    }

    public function testNewInstanceStartsDisconnected(): void
    {
        $redis = new Redis(new NullLogger());

        $this->assertFalse($redis->ping());
        $this->assertFalse($redis->disconnect());
        $this->assertSame(ConnectionState::Nothing, $this->state($redis));
    }

    public function testConnectVerifiesTheServerBeforeItAnswersTrue(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->aliveQueue());

        $this->assertTrue($redis->connect());
        $this->assertSame(ConnectionState::Connected, $this->state($redis));
    }

    public function testConnectFailsWhenTheServerDoesNotAnswer(): void
    {
        $redis = new Redis(new NullLogger());

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn(false);
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertFalse($redis->connect());
        $this->assertSame(ConnectionState::Nothing, $this->state($redis));
    }

    public function testConnectFailsWhenTheServerThrows(): void
    {
        $redis = new Redis(new NullLogger());

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willThrowException(new \RedisException('Connection refused'));
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertFalse($redis->connect());
        $this->assertSame(ConnectionState::Nothing, $this->state($redis));
    }

    public function testBindAfterAFailedConnectReportsFalseRatherThanRaising(): void
    {
        $redis = new Redis(new NullLogger());

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn(false);
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertFalse($redis->connect());
        $this->assertFalse($redis->bindRead('queue'));
        $this->assertFalse($redis->bindWrite('queue'));
        $this->assertSame(ConnectionState::Nothing, $this->state($redis));
    }

    public function testBindOnAVerifiedConnectionRecordsTheRoleAndTheQueue(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->aliveQueue());
        $this->assertTrue($redis->connect());

        $this->assertTrue($redis->bindRead('the-queue'));

        $this->assertSame(ConnectionState::BindRead, $this->state($redis));
        $this->assertSame('the-queue', (new ReflectionProperty(Redis::class, 'queueName'))->getValue($redis));
    }

    /**
     * A second connect() must not throw away a live connection, and with it the jobs
     * this adapter is still holding
     */
    public function testConnectTwiceKeepsTheReservedJobs(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('job-1');
        $job->expects($this->never())->method('release');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->assertTrue($redis->connect());
        $this->assertSame(ConnectionState::BindRead, $this->state($redis));
        $this->assertCount(1, (new ReflectionProperty(Redis::class, 'reservedJobs'))->getValue($redis));
    }

    public function testBindReadRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertFalse($redis->bindRead('queue'));
    }

    public function testBindWriteRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertFalse($redis->bindWrite('queue'));
    }

    public function testBindReadRejectedWhenAlreadyBound(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $this->assertFalse($redis->bindRead('queue'));
    }

    public function testBindWriteRejectedWhenAlreadyBound(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);

        $this->assertFalse($redis->bindWrite('queue'));
    }

    public function testPutTaskRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertInstanceOf(Throwable::class, $redis->putTask('body'));
    }

    public function testPickTaskRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertFalse($redis->pickTask(1));
    }

    public function testAfterWorkSuccessRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertFalse($redis->afterWorkSuccess('job-1'));
    }

    public function testAfterWorkFailedRequiresConnectedClient(): void
    {
        $redis = new Redis(new NullLogger());
        $this->assertFalse($redis->afterWorkFailed('job-1'));
    }

    public function testHasWorkersReportsNotSupported(): void
    {
        $redis = new Redis(new NullLogger());

        $this->assertFalse($redis->hasWorkers('queue'));
    }

    public function testSetWorkTimeoutStoresSubReadTimeoutValue(): void
    {
        $redis = new Redis(new NullLogger());

        $redis->setWorkTimeout(5);

        $this->assertSame(5, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    public function testSetWorkTimeoutClampsBeyondReadTimeout(): void
    {
        $redis = new Redis(new NullLogger());

        $redis->setWorkTimeout(15);

        $this->assertSame(9, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    public function testSetWorkTimeoutFallsBackToSecondWhenReadTimeoutTooSmall(): void
    {
        $redis = new Redis(new NullLogger(), new RedisConfig(readTimeout: 1));

        $redis->setWorkTimeout(15);

        $this->assertSame(1, (new ReflectionProperty(Redis::class, 'blockFor'))->getValue($redis));
    }

    public function testRetryJobAfterStoresRetryAfter(): void
    {
        $redis = new Redis(new NullLogger());

        $redis->retryJobAfter(90);

        $this->assertSame(90, (new ReflectionProperty(Redis::class, 'retryAfter'))->getValue($redis));
    }

    public function testConnectWhenAlreadyConnectedAnswersTrueAndKeepsTheState(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->aliveQueue());

        $this->assertTrue($redis->connect());
        $this->assertTrue($redis->connect());
        $this->assertSame(ConnectionState::Connected, $this->state($redis));
    }

    public function testExceptionHandlerReportsThroughPsr3WithoutRaisingAWarning(): void
    {
        $logger  = new RecordingLogger();
        $redis   = new Redis($logger);
        $app     = (new ReflectionProperty(Redis::class, 'app'))->getValue($redis);
        $handler = $app->make('exception.handler');

        $this->assertInstanceOf(ExceptionHandler::class, $handler);

        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $severity;

            return true;
        });
        try {
            $handler->report(new RuntimeException('boom'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'a queue failure must not reach a user error handler');
        $this->assertLogged($logger, 'boom', 'error');

        $this->assertInstanceOf(Response::class, $handler->render(null, new RuntimeException('boom')));
        $handler->renderForConsole(null, new RuntimeException('boom'));
        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
    }

    public function testPingReportsSuccessWhenQueueIsAlive(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);
        $this->wireManager($redis, $queue);

        $this->assertTrue($redis->ping());
    }

    public function testPingReturnsFalseWhenRedisThrows(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willThrowException(new \RedisException('Lost connection'));

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);
        $this->wireManager($redis, $queue);

        $this->assertFalse($redis->ping());
    }

    public function testDisconnectReleasesReservedJobs(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

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
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $redisManager = $this->createMock(Manager::class);
        $redisManager->method('isConnected')->willThrowException(new RuntimeException('boom'));

        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisManager);
        $this->wireManager($redis, $queue);

        $this->assertTrue($redis->disconnect());
    }

    public function testAfterWorkFailedReleasesReservedJob(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('job-1');
        $job->expects($this->once())->method('release');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->assertTrue($redis->afterWorkFailed('job-1'));
    }

    public function testAfterWorkFailedThrowsOnJobIdMismatch(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('other');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesnt match');

        $redis->afterWorkFailed('job-1');
    }

    public function testAfterWorkSuccessThrowsOnJobIdMismatch(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);

        $job = $this->createMock(RedisJob::class);
        $job->method('getJobId')->willReturn('other');
        $this->setReservedJobs($redis, ['job-1' => $job]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesnt match');

        $redis->afterWorkSuccess('job-1');
    }

    public function testAfterWorkSuccessRejectsAnUnreservedJobId(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);
        $this->setState($redis, ConnectionState::BindRead);

        $this->assertFalse($redis->afterWorkSuccess('never-reserved'));
        $this->assertLogged($logger, 'never-reserved', 'debug');
        $this->assertNotLogged($logger, 'never-reserved', 'error');
    }

    public function testAfterWorkFailedRejectsAnUnreservedJobId(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);
        $this->setState($redis, ConnectionState::BindRead);

        $this->assertFalse($redis->afterWorkFailed('never-reserved'));
        $this->assertLogged($logger, 'never-reserved', 'debug');
        $this->assertNotLogged($logger, 'never-reserved', 'error');
    }

    public function testAnAckRejectsAMissingJobId(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);
        $this->setState($redis, ConnectionState::BindRead);

        $this->assertFalse($redis->afterWorkSuccess(null));
        $this->assertLogged($logger, 'NULL', 'debug');
    }

    public function testPickTaskThrowsWhenReservedJobHasNoId(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);
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
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);
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
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('pop')->willReturn(null);
        $this->wireManager($redis, $queue);

        $this->assertFalse($redis->pickTask());
    }

    public function testPutTaskPushesDelayedJob(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('later')->willReturn('delayed-1');
        $this->wireManager($redis, $queue);

        $this->assertSame('delayed-1', $redis->putTask('body', 5));
        $this->assertSame('delayed-1', $redis->putTask('body', readyWait: 5));
    }

    public function testPutTaskReturnsThrowableWhenPushFails(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('push')->willReturn(null);
        $this->wireManager($redis, $queue);

        $this->assertInstanceOf(Throwable::class, $redis->putTask('body'));
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

    private function setState(Redis $redis, ConnectionState $state): void
    {
        (new ReflectionProperty(Redis::class, 'state'))->setValue($redis, $state);
    }

    private function state(Redis $redis): ConnectionState
    {
        $state = (new ReflectionProperty(Redis::class, 'state'))->getValue($redis);
        $this->assertInstanceOf(ConnectionState::class, $state);

        return $state;
    }

    private function aliveQueue(): Queue
    {
        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');

        return $this->queueAnswering($redisClient);
    }

    private function queueAnswering(\Redis $redisClient): Queue
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);

        return $queue;
    }
}
