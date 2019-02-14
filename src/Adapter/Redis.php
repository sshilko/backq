<?php
namespace BackQ\Adapter;

use Datetime;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use RuntimeException;

/**
 * Class Nsq
 * @package BackQ\Adapter
 */
class Redis extends AbstractAdapter
{
    /** @var string */
    protected $host = 'localhost';

    /** @var int */
    protected $port = 6379;

    const STATE_BINDWRITE = 1;
    const STATE_BINDREAD  = 2;
    const STATE_NOTHING   = 0;

    private const CONNECTION_NAME = 'redis1';
    private const REDIS_DRIVER    = 'phpredis';

    private $connected = false;

    /**
     * @var \Illuminate\Container\Container
     */
    private $app;

    /**
     * @var string
     */
    private $prefix = '';

    /**
     * @see Redis::OPT_READ_TIMEOUT
     * @var int
     */
    private $read_timeout;

    /**
     * @var int
     */
    private $timeout;

    /**
     * @var int
     */
    private $database_id;

    /**
     * @var \Illuminate\Queue\Capsule\Manager
     */
    private $queue;

    /**
     * @var bool
     */
    private $persistent;

    /**
     * @var int
     */
    private $persistent_id;

    /**
     * @var string
     */
    private $auth_password;

    /**
     * @var string
     */
    private $queueName;

    /**
     * @var \Illuminate\Queue\Jobs\RedisJob
     */
    private $reservedJob;

    private $state     = self::STATE_NOTHING;

    private $blockFor = 5;

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
     */
    private $retryAfter = 300;

    public function __construct(string $host          = '127.0.0.1',
                                int    $port          = 6379,
                                bool   $persistent    = false,
                                int    $persistent_id = null,
                                string $prefix        = null,
                                int    $timeout       = 10,
                                int    $read_timeout  = 10,
                                int    $database_id   = 0,
                                string $auth_password = null)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->prefix  = $prefix;
        $this->timeout = $timeout;
        $this->read_timeout  = $read_timeout;
        $this->auth_password = $auth_password;
        $this->persistent = $persistent;
        $this->persistent_id = $persistent_id;

        $this->app  = new Redis\App();

        $this->app->bind('exception.handler', function () {
            return new class implements \Illuminate\Contracts\Debug\ExceptionHandler
            {
                public function report(\Exception $e)
                {
                    \trigger_error($e->getMessage(), E_USER_WARNING);
                }
                public function render($request, \Exception $e)
                {
                    return;
                }
                public function renderForConsole($output, \Exception $e)
                {
                    return;
                }
                public function shouldReport(\Exception $e)
                {
                    return true;
                }
            };
        });
    }

    public function setWorkTimeout(int $seconds = null) {
        /**
         * When using the Redis queue, you may use the block_for configuration option
         * to specify how long the driver should wait for a job to become available before
         * iterating through the worker loop and re-polling the Redis database.
         */
        $this->blockFor = $seconds;
    }

    /**
     * Disconnects from queue
     */
    public function disconnect()
    {
        if (true === $this->connected) {
            try {
                if (self::STATE_BINDREAD == $this->state || self::STATE_BINDWRITE == $this->state) {
                    /** @var \Illuminate\Queue\RedisQueue $redisQueue */
                    if ($this->queue && $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME)) {
                        /** @var \Illuminate\Redis\RedisManager $manager */
                        $manager = $redisQueue->getRedis();
                        if ($manager->isConnected()) {
                            /** @var \Illuminate\Queue\Jobs\RedisJob $redisJob */
                            if ($redisJob = $this->reservedJob) {
                                /**
                                 * Send back to queue
                                 */
                                echo ' RELEASING JOB BACK TO Q';
                                $redisJob->release();
                            }
                            $manager->disconnect();
                        }
                    }
                }
            } catch (\Exception $ex) {
                $this->error(__CLASS__ . ' ' . __FUNCTION__ . ': ' . $ex->getMessage());
            }

            $this->state     = self::STATE_NOTHING;
            $this->stateData = [];
            $this->connected = false;
            return true;
        }
        return false;
    }

    /**
     * Returns TRUE if connection is alive
     */
    public function ping($reconnect = true)
    {
        return true;
    }

    /**
     * After failed work processing
     *
     * @return bool
     */
    public function afterWorkFailed($workId)
    {
        if ($this->connected && ($this->state == self::STATE_BINDREAD ||
                                 $this->state == self::STATE_BINDWRITE)) {

            /** @var \Mallabee\Queue\Drivers\Redis\RedisJob $redisJob */
            if ($redisJob = $this->stateData['job']) {
                /**
                 * Send back to queue
                 */
                $redisJob->release();
            }
            return true;
        }
        return false;
    }

    /**
     * After successful work processing
     *
     * @return bool
     */
    public function afterWorkSuccess($workId)
    {
        if ($this->connected && ($this->state == self::STATE_BINDREAD ||
                                 $this->state == self::STATE_BINDWRITE)) {

            /** @var \Mallabee\Queue\Drivers\Redis\RedisJob $redisJob */
            if ($redisJob = $this->stateData['job']) {
                /**
                 * Delete reserved job from queue
                 */
                $redisJob->delete();
            }
            return true;
        }
        return false;
    }

    private function _connect() {
        $this->app->bind('redis', function() {
            return new \Illuminate\Redis\RedisManager($this->app,
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
                                                                     'database'   => $this->database_id]]);
        });

        $queue = new \Illuminate\Queue\Capsule\Manager($this->app);
        $queue->addConnection(['driver'     => 'redis',
                               'connection' => 'default',
                               'block_for'  => $this->blockFor,
                               'retry_after'=> $this->retryAfter,
                               'queue'      => $this->queueName],
                              self::CONNECTION_NAME);
        $this->queue = $queue;
    }

    /**
     * Prepare to write data into queue
     *
     * @return bool
     */
    public function bindWrite($queue)
    {
        if ($this->connected && $this->state == self::STATE_NOTHING) {
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
     * @return bool
     */
    public function bindRead($queue)
    {
        if ($this->connected && $this->state == self::STATE_NOTHING) {
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
     * @return null|int
     */
    public function hasWorkers($queue = false)
    {
        return true;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return boolean|array [id, payload]
     */
    public function pickTask($timeout = null)
    {
        if ($timeout) {
            $timeout = null;
        }

        if ($this->connected && (self::STATE_BINDREAD == $this->state ||
                                 self::STATE_BINDWRITE == $this->state)) {

            /** @var \Illuminate\Queue\RedisQueue $redisQueue */
            $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME);

            /** @var \Illuminate\Queue\Jobs\RedisJob $redisJob */
            if ($redisJob = $redisQueue->pop()) {
                $this->reservedJob = $redisJob;

                return [$redisJob->getJobId(),
                    /**
                     * Can be a real job object, or not - then just data
                     */
//                    [displayName] => process
//                    [job] => process
//                    [maxTries] =>
//                    [timeout] =>
//                    [data] => 'asdasd'
//                    [id] => YuqIQBxB4qxctKeWJleReiDRvI1xkAw0
//                    [attempts] => 0
                        $redisJob->payload()['data']];
            }
        }
        return false;
    }

    /**
     * Put task into queue
     *
     * @param  string $data The job body.
     * @return integer|boolean `false` on error otherwise an integer indicating
     *         the job id.
     */
    public function putTask($body, $params = array())
    {
        if ($this->connected && (self::STATE_BINDREAD == $this->state ||
                                 self::STATE_BINDWRITE == $this->state)) {

            /** @var \Illuminate\Queue\RedisQueue $instance */
            $instance = $this->queue->getConnection(self::CONNECTION_NAME);

            /**
             * Can put real job objects, or just data, just data for now
             * @see \Illuminate\Queue\Jobs\Job
             */
            if (isset($params[self::PARAM_READYWAIT]) && $params[self::PARAM_READYWAIT] > 0) {
                echo 'DELAYED';
                $delay = new \DateInterval('PT' . ((int) $params[self::PARAM_READYWAIT]) . 'S');
                $instance->later($delay, $this->queueName, $body);
            } else {
                echo 'NOW';
                $instance->push($this->queueName, $body);
            }
            return true;
        }
        return false;
    }

    /**
     * connect and negotiate protocol
     */
    public function connect()
    {
        if ($this->connected) {
            $this->disconnect();
        }
        $this->connected = true;
        return $this->connected;
    }
}