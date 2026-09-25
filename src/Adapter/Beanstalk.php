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
use Override;
use RuntimeException;
use Throwable;
use function is_array;

/**
 * Beanstalk protocol adapter
 * @phpcs:disable
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
    #[Override]
    public function connect(string $host = '127.0.0.1', int $port = 11300, int $timeout = 1, bool $persistent = false, $logger = null): bool
    {
        if (true === $this->connected) {
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

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        $this->workTimeout = $seconds;
    }

    /**
     * This overrides the original Beanstalkd logger
     * @see \Beanstalk\Client._error()
     * @param $msg
     */
    public function error(string $msg): void
    {
        $this->logError($msg);
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     */
    #[Override]
    public function hasWorkers(string $queue = ''): bool
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
                    /**
                     * @var array<array-key, mixed>|false
                     */
                    $result = $this->client->statsTube($queue);
                    if (is_array($result) && isset($result['current-watching'])) {
                        return $result['current-watching'] > 0;
                    }
                } else {
                    /**
                     * Workers at all connected (not very usefull)
                     */
                    /**
                     * @var array<array-key, mixed>|false
                     */
                    $result = $this->client->stats();
                    if (is_array($result) && isset($result['current-workers'])) {
                        return $result['current-workers'] > 0;
                    }
                }
            } catch (RuntimeException $e) {
                $this->error(__FUNCTION__ . ' ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Returns TRUE if connection is alive
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        try {
            /**
             * @todo Any other fast && reliable options to check if socket is alive?
             */
            /**
             * @var array<array-key, mixed>|false $result
             */
            $result = $this->client->stats();
            if (false !== $result) {
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

        return false;
    }

    /**
     * Subscribe for new incoming data
     *
     */
    #[Override]
    public function bindRead(string $queue): bool
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
    #[Override]
    public function bindWrite(string $queue): bool
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
    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        if ($this->connected) {
            try {
                $result = $this->client->reserve($timeout ?? $this->workTimeout);
                /**
                 * @var array{id: int, body: string|false}|false $result
                 */
                if (is_array($result)) {
                    return [$result['id'], $result['body'], []];
                }
            } catch (Throwable $e) {
                $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());

                throw $e;
            }
        }

        return false;
    }

    /**
     * Put task into queue
     *
     * @param string $body The job body.
     *
     * @return false|numeric-string `false` on otherwise an integer indicating the job id.
     */
    #[Override]
    public function putTask(string $body, array $params = []): string|false
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
    #[Override]
    public function afterWorkFailed(int|string|null $workId): bool
    {
        if ($this->connected) {
            try {
                /**
                 * Release task back to queue with default priority and 1 second ready-delay
                 */
                if ($this->client->release((int) $workId, self::PRIORITY_DEFAULT, 1)) {
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
    #[Override]
    public function afterWorkSuccess(int|string|null $workId): bool
    {
        if ($this->connected) {
            try {
                if ($this->client->delete((int) $workId)) {
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
    #[Override]
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
