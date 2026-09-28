<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use BackQ\Adapter\Redis\Connector;
use BackQ\Adapter\Redis\Queue;
use BackQ\Adapter\Redis\RedisConfig;
use Closure;
use DateInterval;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Capsule\Manager;
use Illuminate\Queue\Jobs\RedisJob;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Throwable;
use function assert;
use function count;
use function in_array;
use function var_export;

/**
 * @package BackQ\Adapter
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * Every message is reported through $this?->logger. Psalm reads the nullsafe operator as
 * widening $this to `Redis|null` for the rest of the method, which is not what it means
 * on an injected logger, so the three resulting issues are silenced for this class.
 * @psalm-suppress TypeDoesNotContainNull
 * @psalm-suppress PossiblyNullReference
 * @psalm-suppress PossiblyNullPropertyAssignment
 */
class Redis extends AbstractAdapter
{
    /**
     * @deprecated use ConnectionState, which is the whole state and not a spelling of part of it
     */
    public const int STATE_BINDWRITE = 1;

    /**
     * @deprecated use ConnectionState, which is the whole state and not a spelling of part of it
     */
    public const int STATE_BINDREAD  = 2;

    /**
     * @deprecated use ConnectionState, which is the whole state and not a spelling of part of it
     */
    public const int STATE_NOTHING   = 0;

    /**
     * Whether to emulate blockFor behaviour, if >0 the amount of seconds sleep between polls
     * if not emulated uses .blpop redis implementation, if emulated uses .pop and sleep loop
     */
    public const int BLOCKFOR_EMULATE = 0;

    private const string CONNECTION_NAME  = 'redis1';
    private const string REDIS_DRIVER     = 'phpredis';
    private const string REDIS_DRIVER_OWN = 'redis-backq';

    private Container $app;

    /**
     * The queue connection, built once by ensureConnected()
     *
     * Null until that build, which is a state no operation can reach: the ones that need the
     * connection are gated on isReady(), and taking a role is what builds it. It is nullable so
     * the "already built" question has an answer that is not an uninitialised-property read.
     */
    private ?Manager $queue = null;

    private string $queueName;

    /**
     * @var array<string, RedisJob>
     */
    private array $reservedJobs = [];

    private ConnectionState $state = ConnectionState::Nothing;

    /**
     * Since Laravel 5.8 safe
     * Using the "blocking pop" feature of the Redis queue driver is now safe.
     * Previously, there was a small chance that a queued job could be lost if the Redis server
     * or worker crashed at the same time the job was retrieved.
     * In order to make blocking pops safe, a new Redis list with suffix :notify is created for each
     * Laravel queue.
     *
     */
    private ?int $blockFor = null;

    /**
     * This option specifies how many
     * seconds the queue connection should
     * wait before retrying a job that is being
     * processed. For example, if the value of
     * retry_after is set to 90, the job will be
     * released back onto the queue if it has been
     * processing for 90 seconds without being deleted.
     * Typically, you should set the retry_after value
     * to the maximum number of seconds your jobs should
     * reasonably take to complete processing.
     *
     * max JOB_TTR
     * Migrates any delayed or expired jobs onto the primary queue
     * after retryAfter seconds of being in pending queue
     * migration happens on each pickJob call
     * @see https://github.com/illuminate/queue/blob/11e280c0e2ac9f9bcfe2563461a05cdfefde9179/RedisQueue.php#L184
     *
     * This should be queue property and should be set per-queue
     */
    private ?int $retryAfter = null;

    /**
     * The connection settings live in a value object, so a caller names the settings it
     * means and a wrong one is rejected where the caller can see it.
     *
     * The logger stays first, because PHP forbids a required parameter after an optional
     * one and a PHP 8 deprecation is not a price worth paying.
     */
    public function __construct(LoggerInterface $logger, private readonly RedisConfig $config = new RedisConfig())
    {
        parent::__construct($logger);

        /**
         * connect() builds the connection before anything bound a queue, so it needs a
         * name to build it with. bindRead() and bindWrite() still say which queue they
         * mean, and the name the config carries is the default until they do.
         */
        $this->queueName = $config->queueName;

        $this->app = new Redis\App();

        /**
         * illuminate reports through the container's exception handler. PSR-3 is the
         * channel, so the handler logs and raises nothing: a warning here would be
         * turned into a throwable by a set_error_handler in user code, at a point far
         * from the queue operation that failed.
         */
        $reportable = static function (Throwable $e) use ($logger): void {
            $logger->error($e->getMessage(), ['exception' => $e]);
        };

        $this->app->bind(
            'exception.handler',
            static function () use ($reportable): ExceptionHandler {
                return new class ($reportable) implements ExceptionHandler {
                    /**
                     * @param Closure(Throwable): void $reporter
                     */
                    public function __construct(private readonly Closure $reporter)
                    {
                    }

                    #[Override]
                    public function report(Throwable $e): void
                    {
                        ($this->reporter)($e);
                    }

                    /** @phan-suppress-next-line PhanUndeclaredTypeReturnType */
                    #[Override]
                    public function render($request, Throwable $e): HttpFoundationResponse
                    {
                        return new HttpFoundationResponse();
                    }

                    #[Override]
                    public function renderForConsole($output, Throwable $e): void
                    {
                        return;
                    }

                    #[Override]
                    public function shouldReport(Throwable $e)
                    {
                        return true;
                    }
                };
            }
        );
    }

    /**
     * Enables retrying failed (have been reserved for >= $seconds) jobs
     *
     * @param int $seconds
     */
    public function retryJobAfter(int $seconds): void
    {
        $this->retryAfter = $seconds;
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        /**
         * When using the Redis queue, you may use the block_for configuration option
         * to specify how long the driver should wait for a job to become available before
         * iterating through the worker loop and re-polling the Redis database.
         *
         * Blocking pop is an experimental feature.
         * There is a small chance that a queued job could be lost if the Redis server or worker crashes
         * at the same time the job is retrieved.
         *
         * Declared Safe since Laravel 5.8
         */
        if (null !== $seconds
            && (
                $seconds >= $this->config->timeout
                || $seconds >= $this->config->readTimeout
            )
            && 0 === self::BLOCKFOR_EMULATE
        ) {
            /**
             * Cannot redis.blpop for > read_timeout seconds, wrong settings
             */
            $newWorkTimeout = $this->config->readTimeout - 1;
            if ($newWorkTimeout > 0) {
                $this?->logger->debug(
                    'workTimeout ' . $seconds . ' > read_timeout, using workTimeout = ' . $newWorkTimeout
                );
                $seconds = $newWorkTimeout;
            } else {
                $this?->logger->error('workTimeout ' . $seconds . ' > read_timeout, MUST increase read_timeout');
                $seconds = 1;
            }
        }
        $this->blockFor = $seconds;
    }

    /**
     * Disconnects from queue
     *
     * The gate here is "there is a connection", not "there is a connection and a queue is
     * bound": an adapter that connected and was never bound still has a socket to close,
     * and refusing to close it would leave it claiming to be connected. Releasing the
     * reserved jobs is best effort and a job lost on the way out does not make the close
     * itself fail, so the answer is whether this adapter is unbound and quiet now, not
     * whether every release landed.
     */
    #[Override]
    public function disconnect(): bool
    {
        $this?->logger->debug('Disconnecting');
        if (ConnectionState::Nothing !== $this->state) {
            $this?->logger->debug('Disconnecting, previously connected');

            try {
                if ($this->state->isBound()) {
                    $this?->logger->debug('Disconnecting, state detected');

                    $redisQueue = $this->queue()->getConnection(self::CONNECTION_NAME);
                    \assert($redisQueue instanceof Queue);
                    if ($redisQueue) {
                        $manager = $redisQueue->getRedis();
                        assert($manager instanceof Redis\Manager);
                        if ($manager->isConnected()) {
                            $this?->logger->debug('Disconnecting, state detected, queue is connected');
                            $this?->logger->debug(
                                'Disconnecting, state ' . count(
                                    $this->reservedJobs
                                ) . ' jobs reserved and not finalized'
                            );

                            foreach ($this->reservedJobs as $redisJob) {
                                assert($redisJob instanceof RedisJob);
                                /**
                                 * Send any unsent jobs back to queue, unclean shutdown
                                 */
                                $this?->logger->debug('Disconnecting, releasing reserved job ' . $redisJob->getJobId());
                                $redisJob->release();
                            }
                            $this->reservedJobs = [];

                            $this?->logger->debug('Disconnecting, state detected, disconnecting queue manager');
                            $manager->disconnect();
                        } else {
                            $this?->logger->debug('Disconnecting, state detected, queue is not connected');
                        }
                    }
                }
            } catch (Throwable $ex) {
                $this->log(__FUNCTION__, $ex);
            }

            $this->state = ConnectionState::Nothing;
            $this?->logger->debug('Disconnecting, successful');

            return true;
        }

        $this?->logger->debug('Disconnecting, previously not connected');

        $this?->logger->debug('Disconnecting, failed');

        return false;
    }

    /**
     * Returns TRUE if connection is alive
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        return $this->attempt(__FUNCTION__, function (): bool {
            return $this->pingServer();
        });
    }

    /**
     * After failed work processing
     *
     */
    #[Override]
    public function afterWorkFailed(?string $workId): bool
    {
        return $this->acknowledge($workId, static function (RedisJob $job): void {
            $job->release();
        });
    }

    /**
     * After successful work processing
     *
     */
    #[Override]
    public function afterWorkSuccess(?string $workId): bool
    {
        return $this->acknowledge($workId, static function (RedisJob $job): void {
            $job->delete();
        });
    }

    /**
     * Prepare to write data into queue
     *
     */
    #[Override]
    public function bindWrite(string $queue): bool
    {
        return $this->bind($queue, ConnectionState::BindWrite);
    }

    /**
     * Subscribe for new incoming data
     *
     */
    #[Override]
    public function bindRead(string $queue): bool
    {
        return $this->bind($queue, ConnectionState::BindRead);
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * Redis has no concept of "worker availability" for a queue when using the
     * underlying list-based jobs, so this always reports "no workers".
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        $this?->logger->debug(self::class . '.' . __FUNCTION__ . ' not supported, reporting no workers');

        return false;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return bool|array [id, payload]
     */
    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        /**
         * @todo deny picking task if already picked ?
         */
        $this?->logger->debug(__FUNCTION__);

        if ($timeout) {
            $this->blockFor = $timeout;
        }

        $operation = __FUNCTION__;

        $result = $this->attemptRethrowing($operation, function () use ($operation): bool|array {
            $redisQueue = $this->queue()->getConnection(self::CONNECTION_NAME);
            assert($redisQueue instanceof Queue);
            if ($this->blockFor) {
                $redisQueue->setBlockFor($this->blockFor);
            }

            $this?->logger->debug(
                $operation . ' blocking for ' . (int) $this->blockFor . ' seconds until get a job'
            );
            $redisJob = $redisQueue->pop($this->queueName);

            /** @var RedisJob $redisJob */
            if ($redisJob) {
                $rawJobId = $redisJob->getJobId();
                if (null === $rawJobId) {
                    $redisJob->release();

                    throw new RuntimeException('Reserved job without an id');
                }

                /**
                 * getJobId() is declared to return a string, but hands back whatever the
                 * decoded payload carries, and a hand-written one may carry a number. The
                 * acknowledge methods compare it with === against a string, so it is a
                 * string from here on. The null case is handled above: a cast would turn
                 * it into an empty string instead.
                 *
                 * @psalm-suppress RedundantCastGivenDocblockType
                 */
                $jobId = (string) $rawJobId;
                $this?->logger->debug($operation . ' reserved a job ' . $jobId);

                if (isset($this->reservedJobs[$jobId])) {
                    $redisJob->release();

                    throw new RuntimeException('Already reserved job id ' . $jobId);
                }

                /**
                 * @psalm-suppress RedundantCondition
                 */
                \assert($redisJob instanceof \Illuminate\Queue\Jobs\RedisJob);
                $this->reservedJobs[$jobId] = $redisJob;

                /**
                 * Can be a real job object, or not - then just return ['data']
                 * timeout can be set in worker and in job, job's timeout takes priority
                 */
                //                    [displayName] => process
                //                    [job] => process
                //                    [maxTries] =>
                //                    [timeout] =>
                //                    [data] => 'asdasd'
                //                    [id] => YuqIQBxB4qxctKeWJleReiDRvI1xkAw0
                //                    [attempts] => 0

                $jobPayload = $redisJob->payload();

                /**
                 * @var array{data: mixed} $jobPayload
                 */
                return [$jobId, $jobPayload['data']];
            }

            $this?->logger->debug($operation . ' not reserved a job, nothing in queue');

            return false;
        });
        \assert(\is_bool($result) || \is_array($result));

        return $result;
    }

    /**
     * Put task into queue
     *
     * A TTR is deliberately not accepted: this adapter applies the timeout on pick, because
     * migrate() only reaps rotten reserved and delayed jobs on pop/pick, never on put.
     *
     * @param  string|Stringable $body     The job body.
     * @param  int               $readyWait Seconds to keep the job out of reach before a worker may take it.
     *
     * @return string|Throwable the job id, or the failure
     */
    #[Override]
    public function putTask(string|Stringable $body, int $readyWait = 0): string|Throwable
    {
        $this?->logger->debug(__FUNCTION__);

        $operation = __FUNCTION__;

        return $this->attemptReturning(
            $operation,
            function () use ($body, $readyWait, $operation): string {
                $this?->logger->debug(
                    $operation . ' is connected and ready to: '
                    . (ConnectionState::BindRead === $this->state ? 'read' : 'write')
                );
                $instance = $this->queue()->getConnection(self::CONNECTION_NAME);
                assert($instance instanceof Queue);
                $jobName  = $this->queueName;
                $body     = (string) $body;

                if ($readyWait > 0) {
                    $delay  = new DateInterval('PT' . $readyWait . 'S');
                    $taskId = $instance->later($delay, $jobName, $body, $this->queueName);

                    $this?->logger->debug(
                        $operation . ' ' . ($taskId ? 'pushed' : 'failed push')
                        . ' delayed job (' . $readyWait . ' seconds) ' . $taskId
                    );
                } else {
                    $taskId = $instance->push($jobName, $body, $this->queueName);
                    $this?->logger->debug(
                        $operation . ' ' . ($taskId ? 'pushed' : 'failed push') . ' task without delay ' . $taskId
                    );
                }

                if (null === $taskId) {
                    throw new RuntimeException('push failed');
                }

                $this?->logger->debug($operation . ' return ' . var_export($taskId, true));

                return (string) $taskId;
            }
        );
    }

    /**
     * Connect and negotiate protocol
     *
     * `true` means the server answered, so the caller learns about an unreachable server
     * here and not from the bind that follows. A second connect() answers for the live
     * connection rather than replacing it: the jobs this adapter is still holding belong
     * to it, and dropping them is not something a caller asked for.
     */
    #[Override]
    public function connect(): bool
    {
        $this?->logger->debug(__FUNCTION__);

        return $this->attemptConnect(__FUNCTION__, function (): bool {
            if (ConnectionState::Nothing !== $this->state) {
                $this?->logger->debug(__FUNCTION__ . ' already connected, keeping the live connection');

                return true;
            }

            $this->ensureConnected();

            if (!$this->pingServer()) {
                $this?->logger->error(__FUNCTION__ . ': the redis server did not answer');

                return false;
            }

            $this->state = ConnectionState::Connected;

            return true;
        });
    }

    /**
     * A connected and bound adapter is the only state a queue operation can run in
     */
    #[Override]
    protected function isReady(): bool
    {
        return $this->state->isBound();
    }

    /**
     * Take the role this adapter plays, and the queue it plays it on
     *
     * A role can only be taken on a connection the server confirmed and that is not
     * playing one already, so a caller that never connected learns that here. The build
     * is the one connect() uses too, and it happens once.
     *
     * @param ConnectionState $role BindRead or BindWrite, the state being taken
     */
    private function bind(string $queue, ConnectionState $role): bool
    {
        if (ConnectionState::Connected !== $this->state) {
            $this->preconditionFailed(__FUNCTION__);

            return false;
        }

        $this->queueName = $queue;
        $this->ensureConnected();
        $this->state = $role;

        return true;
    }

    /**
     * Does the server answer right now
     *
     * Not gated on being bound: connect() has to be able to ask this before a role is
     * taken, and a bound adapter is a subset of a connected one.
     */
    private function pingServer(): bool
    {
        $redisQueue = $this->queue()->getConnection(self::CONNECTION_NAME);
        assert($redisQueue instanceof Queue);
        $redis = $redisQueue->getRedis();
        assert($redis instanceof \Redis);
        $pong  = $redis->ping();

        if (in_array($pong, [true, '+PONG'], true)) {
            $this?->logger->debug('ping successful');

            return true;
        }

        return false;
    }

    /**
     * One acknowledge, whichever of the two it is
     *
     * The gate and the three outcomes are shared; the two bodies only differ in what
     * they tell the queue. Written this way because a body flag would put the policy
     * back at the call site, which is the thing this class no longer spells out.
     *
     * @param Closure(RedisJob): void $tell_the_queue what the queue is told about the job
     */
    private function acknowledge(?string $workId, Closure $tell_the_queue): bool
    {
        $this?->logger->debug(__FUNCTION__);

        if (!$this->isReady()) {
            $this->preconditionFailed(__FUNCTION__);

            return false;
        }

        $reserved = $this->reservedJob($workId);
        if (null === $reserved) {
            return false;
        }

        [$redisJob, $key] = $reserved;

        $tell_the_queue($redisJob);
        unset($this->reservedJobs[$key]);

        return true;
    }

    /**
     * The job this id was reserved under, and the key it is held under
     *
     * An id nothing was reserved under is a lost id: the queue was not told, so the
     * acknowledge fails and the job stays in the reserved set until retry_after reaps
     * it. It is a debug record and not a throw, because a failed acknowledge only
     * re-opens the job where a throw would kill the worker over a bookkeeping miss.
     *
     * An id that is reserved under a different job is a caller bug and is raised: the
     * worker holds the id pickTask() reported, so the two can only differ because
     * something between them mixed them up.
     *
     * @return array{0: RedisJob, 1: string}|null the job and the key it is held under
     */
    private function reservedJob(?string $workId): ?array
    {
        $this?->logger->debug('acknowledge currently ' . count($this->reservedJobs) . ' reserved job(s)');

        if (null === $workId || !isset($this->reservedJobs[$workId])) {
            $this?->logger->debug('acknowledge: nothing reserved under id ' . var_export($workId, true));

            return null;
        }

        $redisJob = $this->reservedJobs[$workId];
        $jobId    = $redisJob->getJobId();
        if (null === $jobId || $jobId !== $workId) {
            throw new InvalidArgumentException('Reserved job doesnt match the acknowledged job');
        }

        return [$redisJob, $workId];
    }

    /**
     * Build the queue connection, once
     *
     * The one construction site: connect() needs it before a role is taken, and taking a
     * role needs it to exist, so both come through here and the second one is a no-op.
     */
    private function ensureConnected(): void
    {
        if (null !== $this->queue) {
            return;
        }

        $this?->logger->debug(__FUNCTION__);

        $persistentId = $this->config->persistent && $this->config->persistentId ? getmypid() : 0;

        $this->app->bind('redis', function () use ($persistentId) {
            return new Redis\Manager(
                /** @phan-suppress-next-line PhanTypeMismatchArgument */
                $this->app,
                self::REDIS_DRIVER,
                /**
                 * @see \Illuminate\Redis\Connectors\PhpRedisConnector
                 */
                ['default' => [
                    'host'          => $this->config->host,
                    'password'      => $this->config->authPassword,
                    'prefix'        => $this->config->prefix,
                    'timeout'       => $this->config->timeout,
                    'read_timeout'  => $this->config->readTimeout,
                    'persistent_id' => $persistentId,
                    'port'       => $this->config->port,
                    'persistent' => $this->config->persistent,
                    'database'   => $this->config->databaseId,
                ]]
            );
        });

        $queue = new Manager($this->app);
        $queue->addConnector(self::REDIS_DRIVER_OWN, function () {
            /**
             * Our own connector to use our own queue, to just set setBlockFor() dynamically
             * should not break any compatibility
             */
            return new Connector($this->app['redis']);
        });

        $queue->addConnection(
            [
                'block_for'   => (self::BLOCKFOR_EMULATE > 0 ? null : $this->blockFor),
                'connection'  => 'default',
                'driver'      => self::REDIS_DRIVER_OWN,
                'queue'       => $this->queueName,
                'retry_after' => ($this->retryAfter ?: null),
            ],
            self::CONNECTION_NAME
        );
        $this->queue = $queue;
    }

    /**
     * The queue connection, which every operation that touches the queue is bound to
     *
     * It is built by ensureConnected() before any operation that can reach here, so the null
     * is an invariant break rather than a case to handle. It is raised, not returned, because
     * a caller cannot make this true by retrying: it is raised inside the attempt*() helper of
     * the operation that found it, so it is logged and answered as that operation's failure.
     */
    private function queue(): Manager
    {
        $queue = $this->queue;
        if (null === $queue) {
            throw new RuntimeException(self::class . ' ' . __FUNCTION__ . ': the queue connection is not built');
        }

        return $queue;
    }
}
