<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use BackQ\Adapter\Beanstalk\Client;
use RuntimeException;
use Throwable;
use function is_array;

/**
 * Beanstalk protocol adapter
 *
 * @see https://raw.githubusercontent.com/kr/beanstalkd/master/doc/protocol.txt
 */
class Beanstalk extends AbstractAdapter
{
    public const ADAPTER_NAME = 'beanstalk';

    public const PARAM_PRIORITY  = 'priority';

    public const PRIORITY_DEFAULT = 1024;

    private Client $client;

    private $connected;

    /**
     * Timeout for reserve() command
     *
     */
    private ?int $workTimeout = null;

    /**
     * Connects adapter
     *
     */
    public function connect($host = '127.0.0.1', $port = 11300, $timeout = 1, $persistent = false, $logger = null): bool
    {
        if (true === $this->connected && $this->client) {
            return true;
        }

        try {
            $bconfig = ['host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'persistent' => $persistent,
                'logger'  => ($logger ?: $this)];

            //$this->client = new \Beanstalk\Client($bconfig);
            $this->client = new Client($bconfig);

            if ($this->client->connect()) {
                $this->connected = true;

                return true;
            }
        } catch (Throwable $e) {
            $this->error('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
        }

        return false;
    }

    public function setWorkTimeout(?int $seconds = null)
    {
        $this->workTimeout = $seconds;

        return null;
    }

    /**
     * This overrides the original Beanstalkd logger
     * @see \Beanstalk\Client._error()
     * @param $msg
     */
    public function error($msg): void
    {
        $this->logError($msg);
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     */
    public function hasWorkers($queue = false): ?int
    {
        if ($this->connected) {
            try {
                if ($queue) {
                    # $definedtubes = $this->client->listTubes();
                    # if (!empty($definedtubes) && in_array($queue, $definedtubes)) {
                    # Because we already binded to a queue, it will be always shown in list
                    
                    /**
                     * Workers watching queue
                     *
                     * rarely fails with NOT_FOUND even when we binded (use %tube) successfuly before
                     * failure produces error-log entries
                     */
                    $result = $this->client->statsTube($queue);
                    if ($result && is_array($result) && isset($result['current-watching'])) {
                        return $result['current-watching'];
                    }
                } else {
                    /**
                     * Workers at all connected (not very usefull)
                     */
                    $result = $this->client->stats();
                    if ($result && is_array($result) && isset($result['current-workers'])) {
                        return $result['current-workers'];
                    }
                }
            } catch (RuntimeException $e) {
                $this->error(__FUNCTION__ . ' ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Returns TRUE if connection is alive
     */
    public function ping($reconnect = true)
    {
        try {
            /**
             * @todo Any other fast && reliable options to check if socket is alive?
             */
            $result = $this->client->stats();
            if ($result) {
                return true;
            }

            if ($reconnect) {
                if (true === $this->client->connect()) {
                    return $this->ping(false);
                }
            }
        } catch (RuntimeException $e) {
            $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
        }
    }

    /**
     * Subscribe for new incoming data
     *
     */
    public function bindRead($queue): bool
    {
        if ($this->connected) {
            try {
                if ($this->client->watch($queue)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Prepare to write data into queue
     *
     */
    public function bindWrite($queue): bool
    {
        if ($this->connected) {
            try {
                if ($this->client->useTube($queue)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return bool|array [id, payload]
     */
    public function pickTask()
    {
        if ($this->connected) {
            try {
                $result = $this->client->reserve($this->workTimeout);
                if (is_array($result)) {
                    return [$result['id'], $result['body'], []];
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Pick many tasks from queue
     *
     * @param int $max maximum number of tasks to reserve
     * @param int $waitForJob should we try and wait for N seconds for job to be available, default not to wait
     *
     * @return bool|array of [id, payload]
     */
    public function pickTasks($max, $waitForJob = 0)
    {
        if ($this->connected) {
            try {
                $result = [];
                for ($i = 0; $i < $max; $i++) {
                    /**
                     * Pick a task or return immediattely if no (more) tasks available
                     */
                    $taskreserve = $this->client->reserve($waitForJob);
                    if (is_array($taskreserve)) {
                        $result[] = [$taskreserve['id'], $taskreserve['body']];
                    } else {
                        break;
                    }
                }

                return $result;
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Put task into queue
     *
     * @param  string $data The job body.
     * @return int|bool `false` on  otherwise an integer indicating
 * the job id.
     */
    public function putTask($body, $params = [])
    {
        if ($this->connected) {
            try {
                $priority  = self::PRIORITY_DEFAULT;
                $readywait = 0;
                $jobttr    = self::JOBTTR_DEFAULT;

                if (isset($params[self::PARAM_PRIORITY])) {
                    $priority  = $params[self::PARAM_PRIORITY];
                }

                if (isset($params[self::PARAM_READYWAIT])) {
                    $readywait = $params[self::PARAM_READYWAIT];
                }

                if (isset($params[self::PARAM_JOBTTR])) {
                    $jobttr    = $params[self::PARAM_JOBTTR];
                }

                $result = $this->client->put($priority, $readywait, $jobttr, $body);

                if (false !== $result) {
                    return (string) $result;
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * After failed work processing
     *
     */
    public function afterWorkFailed($workId): bool
    {
        if ($this->connected) {
            try {
                /**
                 * Release task back to queue with default priority and 1 second ready-delay
                 */
                if ($this->client->release($workId, self::PRIORITY_DEFAULT, 1)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * After successful work processing
     *
     */
    public function afterWorkSuccess($workId): bool
    {
        if ($this->connected) {
            try {
                if ($this->client->delete($workId)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Disconnects from queue
     *
     */
    public function disconnect(): bool
    {
        if (true === $this->connected) {
            try {
                $this->client->disconnect();
                $this->connected = false;

                return true;
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }

        return false;
    }
}
