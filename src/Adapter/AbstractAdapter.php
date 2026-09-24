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

use Psr\Log\LoggerInterface;
use function trigger_error;
use const E_USER_WARNING;

abstract class AbstractAdapter
{
    public const string PARAM_JOBTTR    = 'jobttr';
    public const string PARAM_READYWAIT = 'readywait';

    public const int JOBTTR_DEFAULT  = 60;

    /**
     * Whether logError should always call trigger_error
     */
    protected bool $triggerErrorOnError = true;

    protected ?LoggerInterface $logger = null;

    /**
     * Connect to server
     */
    abstract public function connect(): bool;

    /**
     * Disconnect from server
     */
    abstract public function disconnect(): bool;

    /**
     * Subscribe to server queue
     *
     * @param string $queue
     */
    abstract public function bindRead(string $queue): bool;

    /**
     * Prepare to write to server queue
     *
     * @param string $queue
     */
    abstract public function bindWrite(string $queue): bool;

    /**
     * Get job to process
     *
     * @param int|null $timeout seconds
     *
     * @return bool|array [id, payload]
     */
    abstract public function pickTask(?int $timeout = null): bool|array;

    /**
     * Put job to process
     *
     * @param string $body
     * @param array $params
     *
     * @return bool|string|int job id or false on failure
     */
    abstract public function putTask(string $body, array $params = []): bool|string|int;

    /**
     * Acknowledge server: callback after successfully processing job
     *
     * @param int|string|null $workId
     */
    abstract public function afterWorkSuccess(int|string|null $workId): bool;

    /**
     * Acknowledge server: callback after failing to process job
     *
     * @param int|string|null $workId
     */
    abstract public function afterWorkFailed(int|string|null $workId): bool;

    /**
     * Ping if still has alive connection to server
     *
     * @param bool $reconnect
     */
    abstract public function ping(bool $reconnect = true): bool;

    /**
     * Is there workers ready for job immediately
     *
     * @param string $queue
     */
    abstract public function hasWorkers(string $queue): bool;

    /**
     * Preffered limit of one work cycle
     * @param int|null $seconds
     */
    abstract public function setWorkTimeout(?int $seconds = null): void;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param bool $triggerError
     */
    public function setTriggerErrorOnError(bool $triggerError): void
    {
        $this->triggerErrorOnError = $triggerError;
    }

    /**
     * @param string $message
     */
    public function logInfo(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->info($message);
        }
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->debug($message);
        }
    }

    /**
     * @param string $message
     */
    public function logError(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->error($message);
        }

        if ($this->triggerErrorOnError) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
