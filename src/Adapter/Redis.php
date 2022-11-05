<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2021 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use BackQ\Adapter\Redis\Connector;
use BackQ\Adapter\Redis\Queue;
use DateInterval;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Capsule\Manager;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Redis\RedisManager;
use InvalidArgumentException;
use Predis\ClientInterface;
use RuntimeException;
use Throwable;
use function assert;
use function count;
use function sleep;
use function trigger_error;
use function var_export;
use const E_USER_WARNING;

/**
 * @package BackQ\Adapter
 */
class Redis extends AbstractAdapter
{
    public const STATE_BINDWRITE = 1;
    public const STATE_BINDREAD  = 2;
    public const STATE_NOTHING   = 0;

    /**
     * Whether to emulate blockFor behaviour, if >0 the amount of seconds sleep between polls
     * if not emulated uses .blpop redis implementation, if emulated uses .pop and sleep loop
     */
    public const BLOCKFOR_EMULATE = 0;

    private const CONNECTION_NAME  = 'redis1';
    private const REDIS_DRIVER     = 'phpredis';
    private const REDIS_DRIVER_OWN = 'redis-backq';

    protected string $host = 'localhost';

    protected int $port = 6379;

    private $connected = false;

    private Container $app;

    private string $prefix = '';

    /**
     * @see Redis::OPT_READ_TIMEOUT
     */
    private int $read_timeout;

    private int $timeout;

    private int $database_id = 0;

    private Manager $queue;

    private bool $persistent;

    private int $persistent_id;

    private string $auth_password;

    private string $queueName;

    /**
     * @var []\Illuminate\Queue\Jobs\RedisJob
     */
    private $reservedJobs = [];

    private $state     = self::STATE_NOTHING;

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
    private $retryAfter = null;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        bool $persistent = false,
        ?int $persistent_id = null,
        ?string $prefix = null,
        int $timeout = 10,
        int $read_timeout = 10,
        int $database_id = 0,
        ?string $auth_password = null
    ) {
        $this->host    = $host;
        $this->port    = $port;
        $this->prefix  = $prefix;
        $this->timeout = $timeout;
        $this->read_timeout  = $read_timeout;
        $this->auth_password = $auth_password;
        $this->persistent    = $persistent;
        $this->persistent_id = $persistent_id;
        $this->database_id   = $database_id;

        $this->app  = new Redis\App();

        $this->app->bind('exception.handler', static function () {
            return new class implements ExceptionHandler
            {
                public function report(Throwable $e): void
                {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }

                public function render($request, Throwable $e): void
                {
                    return;
                }

                public function renderForConsole($output, Throwable $e): void
                {
                    return;
                }

                public function shouldReport(Throwable $e)
                {
                    return true;
                }
            };
        });
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
        if (($seconds >= $this->timeout || $seconds >= $this->read_timeout) && 0 === self::BLOCKFOR_EMULATE) {
            /**
             * Cannot redis.blpop for > read_timeout seconds, wrong settings
             */
            $newWorkTimeout = (int) $this->read_timeout - 1;
            if ($newWorkTimeout > 0) {
                $this->logDebug('workTimeout ' . $seconds . ' > read_timeout, using workTimeout = ' . $newWorkTimeout);
                $seconds = $newWorkTimeout;
            } else {
                $this->logError('workTimeout ' . $seconds . ' > read_timeout, MUST increase read_timeout');
                $seconds = 1;
            }
        }
        $this->blockFor = $seconds;
    }

    /**
     * Disconnects from queue
     */
    public function disconnect()
    {
        $this->logDebug('Disconnecting');
        if (true === $this->connected) {
            $this->logDebug('Disconnecting, previously connected');

            try {
                if (self::STATE_BINDREAD === $this->state || self::STATE_BINDWRITE === $this->state) {
                    $this->logDebug('Disconnecting, state detected');

                    /** @var Queue $redisQueue */
                    if ($this->queue && $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME)) {
                        $manager = $redisQueue->getRedis();
                        assert($manager instanceof RedisManager);
                        if ($manager->isConnected()) {
                            $this->logDebug('Disconnecting, state detected, queue is connected');
                            $this->logDebug(
                                'Disconnecting, state ' . count(
                                    $this->reservedJobs
                                ) . ' jobs reserved and not finalized'
                            );

                            foreach ($this->reservedJobs as $redisJob) {
                                assert($redisJob instanceof RedisJob);
                                /**
                                 * Send any unsent jobs back to queue, unclean shutdown
                                 */
                                $this->logDebug('Disconnecting, releasing reserved job ' . $redisJob->getJobId());
                                $redisJob->release();
                            }
                            $this->reservedJobs = [];

                            $this->logDebug('Disconnecting, state detected, disconnecting queue manager');
                            $manager->disconnect();
                        } else {
                            $this->logDebug('Disconnecting, state detected, queue is not connected');
                        }
                    }
                }
            } catch (Throwable $ex) {
                $errmsg = self::class . ' ' . __FUNCTION__ . ': ' . $ex->getMessage();
                $this->logError($errmsg);
            }

            $this->state     = self::STATE_NOTHING;
            $this->stateData = [];
            $this->connected = false;
            $this->logDebug('Disconnecting, successful');

            return true;
        } else {
            $this->logDebug('Disconnecting, previously not connected');
        }

        $this->logDebug('Disconnecting, failed');

        return false;
    }

    /**
     * Returns TRUE if connection is alive
     */
    public function ping($reconnect = true)
    {
        if ($this->connected && $this->queue) {
            $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME);
            assert($redisQueue instanceof Queue);
            $redis = $redisQueue->getRedis();
            assert($redis instanceof ClientInterface);
            $pong  = $redis->ping();

            if (true === $pong || '+PONG' === $pong) {
                $this->logDebug(__FUNCTION__ . ' successful');

                return true;
            }
        }
        $this->logDebug(__FUNCTION__ . ' failed');

        return false;
    }

    /**
     * After failed work processing
     *
     */
    public function afterWorkFailed($workId): bool
    {
        $this->logDebug(__FUNCTION__);

        if ($this->connected && (self::STATE_BINDREAD === $this->state ||
                                 self::STATE_BINDWRITE === $this->state)) {
            $this->logDebug(__FUNCTION__ . ' currently ' . (int) $this->reservedJobs . ' reserved job(s)');

            /** @var RedisJob $redisJob */
            if (isset($this->reservedJobs[$workId]) && $redisJob = $this->reservedJobs[$workId]) {
                /**
                 * Delete reserved job from queue
                 */
                if ($redisJob->getJobId() === $workId) {
                    $this->logDebug(__FUNCTION__ . ' releasing back to queue / failed to process ' . $workId . ' job');

                    $redisJob->release();
                    unset($this->reservedJobs[$workId]);
                } else {
                    throw new InvalidArgumentException('Reserved job doesnt match failed job, nothing to release');
                }
            }

            return true;
        }

        return false;
    }

    /**
     * After successful work processing
     *
     */
    public function afterWorkSuccess($workId): bool
    {
        $this->logDebug(__FUNCTION__);

        if ($this->connected && (self::STATE_BINDREAD === $this->state ||
                                 self::STATE_BINDWRITE === $this->state)) {
            $this->logDebug(__FUNCTION__ . ' currently ' . (int) $this->reservedJobs . ' reserved job(s)');

            /** @var RedisJob $redisJob */
            if (isset($this->reservedJobs[$workId]) && $redisJob = $this->reservedJobs[$workId]) {
                /**
                 * Delete reserved job from queue
                 */
                if ($redisJob->getJobId() === $workId) {
                    $this->logDebug(__FUNCTION__ . ' releasing completed ' . $workId . ' job');

                    $redisJob->delete();
                    unset($this->reservedJobs[$workId]);
                } else {
                    throw new InvalidArgumentException('Reserved job doesnt match successful job');
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Prepare to write data into queue
     *
     */
    public function bindWrite($queue): bool
    {
        if ($this->connected && self::STATE_NOTHING === $this->state) {
            $this->state = self::STATE_BINDWRITE;
            $this->queueName = $queue;
            $this->_connect();

            return true;
        }

        return false;
    }

    /**
     * Subscribe for new incoming data
     *
     */
    public function bindRead($queue): bool
    {
        if ($this->connected && self::STATE_NOTHING === $this->state) {
            $this->state = self::STATE_BINDREAD;
            $this->queueName = $queue;
            $this->_connect();

            return true;
        }

        return false;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * @deprecated
     */
    public function hasWorkers($queue = false): ?int
    {
        return true;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return bool|array [id, payload]
     */
    public function pickTask($timeout = null)
    {
        /**
         * @todo deny picking task if already picked ?
         */
        $this->logDebug(__FUNCTION__);

        if ($timeout) {
            $this->blockFor = $timeout;
        }

        if ($this->connected && (self::STATE_BINDREAD === $this->state ||
                                 self::STATE_BINDWRITE === $this->state)) {
            $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME);
            assert($redisQueue instanceof Queue);
            if ($this->blockFor) {
                if (!self::BLOCKFOR_EMULATE) {
                    $redisQueue->setBlockFor($this->blockFor);
                }
            }

            $redisJob = null;

            $this->logDebug(__FUNCTION__ . ' blocking for ' . (int) $this->blockFor . ' seconds until get a job');
            if (self::BLOCKFOR_EMULATE) {
                /**
                 * Pop immediatelly
                 */
                $redisJob = $redisQueue->pop($this->queueName);

                /**
                 * Sleep loop and pop if didnt get anything
                 */
                $i = $this->blockFor;
                while ($i > 0 && !$redisJob) {
                    /**
                     * @todo increase responsiveness of queue by using usleep instead,
                     *       any task can be delayed by max SLEEP seconds instead of realtime
                     */
                    sleep(1);
                    $redisJob = $redisQueue->pop($this->queueName);
                    $i--;

                    $this->logDebug(__FUNCTION__ . ' slept for 1 second');
                }
            } else {
                $redisJob = $redisQueue->pop($this->queueName);
            }

            /** @var RedisJob $redisJob */
            if ($redisJob) {
                $this->logDebug(__FUNCTION__ . ' reserved a job ' . $redisJob->getJobId());

                if (isset($this->reservedJobs[$redisJob->getJobId()])) {
                    $redisJob->release();

                    throw new RuntimeException('Already reserved job id ' . $redisJob->getJobId());
                }

                $this->reservedJobs[$redisJob->getJobId()] = $redisJob;

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

                return [$redisJob->getJobId(),
                    $redisJob->payload()['data']];
            }

            $this->logDebug(__FUNCTION__ . ' not reserved a job, nothing in queue');
        }

        return false;
    }

    /**
     * Put task into queue
     *
     * @param  string $data The job body.
     * @return string|false job-id on success
     */
    public function putTask($body, $params = [])
    {
        $this->logDebug(__FUNCTION__);

        if ($this->connected && (self::STATE_BINDREAD === $this->state ||
                                 self::STATE_BINDWRITE === $this->state)) {
            $this->logDebug(
                __FUNCTION__ . ' is connected and ready to: ' . (self::STATE_BINDREAD === $this->state ? 'read' : 'write')
            );
            $instance = $this->queue->getConnection(self::CONNECTION_NAME);
            assert($instance instanceof Queue);
            $jobName  = $this->queueName;

            /**
             * Can put real job objects, or just data, just data for now
             * @see \Illuminate\Queue\Jobs\Job
             */
            //\Illuminate\Queue\SerializableClosure::removeSecurityProvider();
            //\Illuminate\Queue\SerializableClosure::setSecretKey(self::ENCRYPTION_KEY);
            //$dummyClosure = function() use ($body) { return $body; };
            //$jobName = \Illuminate\Queue\SerializableClosure::from($dummyClosure);
            //$jobName = \Illuminate\Queue\CallQueuedClosure::class;

            //if (isset($params[self::PARAM_JOBTTR]) && $params[self::PARAM_JOBTTR] > 0) {
                /**
                 * TTR is only used on picking in Redis adapter,
                 * migrate() that moves rotten reserved or delayed jobs only happen on pop/pick
                 * NOT in put
                 * Ignoring TTR
                 */
            //}

            if (isset($params[self::PARAM_READYWAIT]) && $params[self::PARAM_READYWAIT] > 0) {
                $delay = new DateInterval('PT' . ((int) $params[self::PARAM_READYWAIT]) . 'S');
                $taskId = $instance->later($delay, $jobName, $body, $this->queueName);

                $this->logDebug(
                    __FUNCTION__ . ' ' . ($taskId ? 'pushed' : 'failed push') . ' delayed job (' . (int) $params[self::PARAM_READYWAIT] . ' seconds) ' . $taskId
                );
            } else {
                $taskId = $instance->push($jobName, $body, $this->queueName);
                $this->logDebug(
                    __FUNCTION__ . ' ' . ($taskId ? 'pushed' : 'failed push') . ' task without delay ' . $taskId
                );
            }

            if (null === $taskId) {
                $this->logDebug(__FUNCTION__ . ' return false');

                return false;
            }

            $this->logDebug(__FUNCTION__ . ' return ' . var_export($taskId, true));

            return $taskId;
        }
        $this->logDebug(__FUNCTION__ . ' return false');

        return false;
    }

    /**
     * connect and negotiate protocol
     */
    public function connect()
    {
        $this->logDebug(__FUNCTION__);

        if ($this->connected) {
            $this->disconnect();
        }
        $this->connected = true;

        return $this->connected;
    }

    private function _connect(): void
    {
        $this->logDebug(__FUNCTION__);

        //$this->app->singleton('encrypter', function () {
        //    return new \Illuminate\Encryption\Encrypter('383baa56ab');
        //});

        $this->app->bind('redis', function () {
            return new RedisManager(
                $this->app,
                self::REDIS_DRIVER,
                /**
                                                       * @see \Illuminate\Redis\Connectors\PhpRedisConnector
                                                       */
                                                      ['default' => ['host'          => $this->host,
                                                          'password'      => $this->auth_password,
                                                          'prefix'        => $this->prefix,
                                                          'timeout'       => $this->timeout,
                                                          'read_timeout'  => $this->read_timeout,
                                                          'persistent_id' => $this->persistent_id,
                                                          'port'       => $this->port,
                                                          'persistent' => $this->persistent,
                                                          'database'   => $this->database_id]]
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
            ['driver'     => self::REDIS_DRIVER_OWN,
                'connection' => 'default',
                'block_for'  => (self::BLOCKFOR_EMULATE > 0 ? null : $this->blockFor),
                'retry_after'=> ($this->retryAfter ?: null),
                'queue'      => $this->queueName],
            self::CONNECTION_NAME
        );
        $this->queue = $queue;
    }
}
