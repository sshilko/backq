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
    public const PARAM_JOBTTR    = 'jobttr';
    public const PARAM_READYWAIT = 'readywait';

    public const JOBTTR_DEFAULT  = 60;

    /**
     * Whether logError should always call trigger_error
     */
    protected bool $triggerErrorOnError = true;

    protected LoggerInterface $logger;

    /**
     * Connect to server
     */
    abstract public function connect(): void;

    /**
     * Disconnect from server
     */
    abstract public function disconnect(): void;

    /**
     * Subscribe to server queue
     */
    abstract public function bindRead($queue): void;

    /**
     * Prepare to write to server queue
     */
    abstract public function bindWrite($queue): void;

    /**
     * Get job to process
     * @param int $timeout seconds
     */
    abstract public function pickTask(): void;

    /**
     * Put job to process
     */
    abstract public function putTask($body, $params = []): void;

    /**
     * Acknowledge server: callback after successfully processing job
     */
    abstract public function afterWorkSuccess($workId): void;

    /**
     * Acknowledge server: callback after failing to process job
     */
    abstract public function afterWorkFailed($workId): void;

    /**
     * Ping if still has alive connection to server
     */
    abstract public function ping(): void;

    /**
     * Is there workers ready for job immediately
     */
    abstract public function hasWorkers($queue): void;

    /**
     * Preffered limit of one work cycle
     * @param int|null $seconds
     *
     * @return null
     */
    abstract public function setWorkTimeout(?int $seconds = null);

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
        if ($this->logger) {
            $this->logger->info($message);
        }
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }

    /**
     * @param string $message
     */
    public function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        }

        if ($this->triggerErrorOnError) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
