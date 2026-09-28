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

    /**
     * @var list<array<int, mixed>> the worker-registry words the spy Redis was sent
     */
    private array $redisCalls = [];

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

    /**
     * The messages connect() writes from inside its closure must name connect()
     *
     * __FUNCTION__ is the string `{closure}` inside a closure, not the method name, so
     * this pins the name being carried in rather than read from the wrong scope. It fails
     * on the literal `{closure}` reaching the log.
     */
    public function testTheConnectMessagesNameTheMethodAndNotTheClosure(): void
    {
        $logger  = new RecordingLogger();
        $redis   = new Redis($logger);
        $this->setState($redis, ConnectionState::BindRead);

        $this->assertTrue($redis->connect());

        $this->assertLogged($logger, 'connect already connected, keeping the live connection', 'debug');
        $this->assertNotLogged($logger, '{closure}');
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

    /**
     * A worker announces itself when it takes the read role, which is the only thing that
     * makes an adapter a worker, so this is the only place an announcement can come from
     */
    public function testBindReadAnnouncesALease(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->spyRedis());
        $this->assertTrue($redis->connect());

        $this->assertTrue($redis->bindRead('the-queue'));

        $this->assertCount(1, $this->redisCalls);
        $this->assertSame('zadd', $this->redisCalls[0][0]);
        $this->assertSame('backq:workers:the-queue', $this->redisCalls[0][1]);

        /**
         * The score is an absolute expiry on the server's clock, not a duration, so two hosts
         * with skewed clocks cannot make a dead worker look alive. It arrives as a float
         * because that is what phpredis' own zadd signature declares.
         */
        $this->assertSame(1000300.0, $this->redisCalls[0][2]);
        $this->assertMatchesRegularExpression('/^\d+-[0-9a-f]{16}$/', $this->redisCalls[0][3][0]);
    }

    /**
     * A publisher is not a worker, and hasWorkers() must not be able to find itself
     */
    public function testBindWriteDoesNotAnnounceALease(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->spyRedis());
        $this->assertTrue($redis->connect());

        $this->assertTrue($redis->bindWrite('the-queue'));

        $this->assertSame([], $this->redisCalls);
    }

    /**
     * Give the lease back on the way out, so a publisher does not wait out the TTL on an
     * adapter that is gone
     */
    public function testDisconnectReleasesTheLease(): void
    {
        $redis = new Redis(new NullLogger());
        $this->wireManager($redis, $this->spyRedis());
        $this->assertTrue($redis->connect());
        $this->assertTrue($redis->bindRead('the-queue'));

        $this->assertTrue($redis->disconnect());

        $this->assertCount(2, $this->redisCalls);
        $this->assertSame('zrem', $this->redisCalls[1][0]);
        $this->assertSame('backq:workers:the-queue', $this->redisCalls[1][1]);
        $this->assertSame($this->redisCalls[0][3][0], $this->redisCalls[1][2]);
    }

    /**
     * A worker that cannot write the registry must still work: a Redis ACL that permits the
     * queue commands and not ZADD must not take down every worker in the deployment
     */
    public function testTheLeaseIsNeverLoadBearingForAWorkCycle(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');
        $redisClient->method('time')->willThrowException(
            new \RedisException('NOPERM this user has no permissions to run zadd')
        );
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertTrue($redis->connect());

        /**
         * The whole point: the lease failed, the bind did not. attempt() logged it and the
         * work cycle never saw it.
         */
        $this->assertTrue($redis->bindRead('the-queue'));
        $this->assertLogged($logger, 'adapter lease exception: NOPERM', 'error');
    }

    /**
     * Every pick cycle runs the renew, so it is rate-limited rather than written per cycle.
     * The mock answers 0 to every ZADD after the first, which is what Redis answers to a
     * member that is already there, so this also pins that a renewal which added nothing is
     * still a renewal that happened: a lease() reading that 0 as a failure would leave
     * $leasedAt null and write a third time.
     */
    public function testPickTaskRenewsTheLeaseAtMostEveryTtlOverThree(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindRead);
        $this->setQueueName($redis, 'the-queue');

        $queue = $this->createMock(Queue::class);
        $queue->method('pop')->willReturn(null);
        $this->wireManager($redis, $this->spyRedis(1000000, 0, $queue));

        $this->assertFalse($redis->pickTask());
        $this->assertCount(1, $this->redisCalls, 'the first pick cycle renews, $leasedAt starts null');

        $this->assertFalse($redis->pickTask());
        $this->assertCount(1, $this->redisCalls, 'the second cycle is inside the interval and writes nothing');
    }

    /**
     * The answer comes from a registry, not from this process, so the leases counted are ones
     * this adapter never wrote
     */
    public function testHasWorkersCountsTheLeasesItDidNotWrite(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);
        $this->wireManager($redis, $this->spyRedis(1000000, 2));

        $this->assertTrue($redis->hasWorkers('the-queue'));
    }

    /**
     * The queue nobody is consuming, and the case that must not answer true: a backlog is not
     * a worker
     */
    public function testHasWorkersIsFalseWhenNoWorkerHasALease(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);
        $this->wireManager($redis, $this->spyRedis(1000000, 0));

        $this->assertFalse($redis->hasWorkers('the-queue'));
    }

    /**
     * A worker killed with SIGKILL leaves its lease behind, and only the prune is what makes
     * the count mean "alive right now"
     */
    public function testHasWorkersPrunesExpiredLeasesBeforeCounting(): void
    {
        $redis = new Redis(new NullLogger());
        $this->setState($redis, ConnectionState::BindWrite);
        $this->wireManager($redis, $this->spyRedis(1000000, 0));

        $redis->hasWorkers('the-queue');

        $this->assertSame(['zremrangebyscore', 'zcard'], array_column($this->redisCalls, 0));
        $this->assertSame('backq:workers:the-queue', $this->redisCalls[0][1]);
        $this->assertSame(['-inf', '1000000'], [$this->redisCalls[0][2], $this->redisCalls[0][3]]);
    }

    /**
     * The operation is refused before any I/O, and the record names hasWorkers() rather than
     * the closure it is written from
     */
    public function testHasWorkersIsFalseOnAnUnboundAdapter(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);

        $this->assertFalse($redis->hasWorkers('the-queue'));
        $this->assertLogged($logger, 'adapter hasWorkers: not connected or not bound', 'debug');
        $this->assertNotLogged($logger, '{closure}');
    }

    /**
     * A protocol break must not read as "no worker has ever existed", so it is logged as the
     * failure it is and the operation answers false
     */
    public function testHasWorkersIsFalseWhenRedisDoesNotAnswer(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);
        $this->setState($redis, ConnectionState::BindWrite);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('time')->willThrowException(new \RedisException('Lost connection'));
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertFalse($redis->hasWorkers('the-queue'));
        $this->assertLogged($logger, 'adapter hasWorkers exception: Lost connection', 'error');
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

    /**
     * A \Redis that records the worker-registry words the adapter sends it
     *
     * Every word is recorded as [command, key, ...arguments] in the order it arrived, which
     * is what the ordering and the token assertions read. ZADD answers 1 for the first member
     * and 0 for every one after it, which is what Redis answers to a member that is already
     * in the set: a renewal adds nothing and is not a failure.
     *
     * Every word is recorded in $this->redisCalls, which this method clears on the way in.
     *
     * @param int    $serverNow   the seconds the server's TIME reports
     * @param int    $workerCount the leases ZCARD finds, the answer a publisher gets
     * @param ?Queue $queue       a queue to wire instead, for a caller that stubs pop()
     */
    private function spyRedis(int $serverNow = 1000000, int $workerCount = 0, ?Queue $queue = null): Queue
    {
        $added = 0;
        $this->redisCalls = [];

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');
        $redisClient->method('isConnected')->willReturn(false);
        $redisClient->method('time')->willReturn([(string) $serverNow, '0']);
        $redisClient->method('zadd')->willReturnCallback(
            function (string $key, mixed $score, mixed ...$members) use (&$added): int {
                $this->redisCalls[] = ['zadd', $key, $score, $members];
                $added++;

                return 1 === $added ? 1 : 0;
            }
        );
        $redisClient->method('zrem')->willReturnCallback(
            function (mixed $key, mixed $member): int {
                $this->redisCalls[] = ['zrem', $key, $member];

                return 1;
            }
        );
        $redisClient->method('zremrangebyscore')->willReturnCallback(
            function (string $key, string $start, string $end): int {
                $this->redisCalls[] = ['zremrangebyscore', $key, $start, $end];

                return 0;
            }
        );
        $redisClient->method('zcard')->willReturnCallback(
            function (string $key) use ($workerCount): int {
                $this->redisCalls[] = ['zcard', $key, $workerCount];

                return $workerCount;
            }
        );

        if (null === $queue) {
            return $this->queueAnswering($redisClient);
        }

        $queue->method('getRedis')->willReturn($redisClient);

        return $queue;
    }

    private function queueAnswering(\Redis $redisClient): Queue
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('getRedis')->willReturn($redisClient);

        return $queue;
    }
}
