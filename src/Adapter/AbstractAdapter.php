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

abstract class AbstractAdapter
{
    const PARAM_JOBTTR    = 'jobttr';
    const PARAM_READYWAIT = 'readywait';

    const JOBTTR_DEFAULT  = 60;

    /**
     * Whether logError should always call trigger_error
     * @var bool
     */
    protected $triggerErrorOnError = true;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Connect to server
     */
    abstract public function connect();

    /**
     * Disconnect from server
     */
    abstract public function disconnect();

    /**
     * Subscribe to server queue
     */
    abstract public function bindRead($queue);

    /**
     * Prepare to write to server queue
     */
    abstract public function bindWrite($queue);

    /**
     * Get job to process
     * @param int $timeout seconds
     */
    abstract public function pickTask();

    /**
     * Put job to process
     */
    abstract public function putTask($body, $params = array());

    /**
     * Acknowledge server: callback after successfully processing job
     */
    abstract public function afterWorkSuccess($workId);

    /**
     * Acknowledge server: callback after failing to process job
     */
    abstract public function afterWorkFailed($workId);

    /**
     * Ping if still has alive connection to server
     */
    abstract public function ping();

    /**
     * Is there workers ready for job immediately
     */
    abstract public function hasWorkers($queue);

    /**
     * Preffered limit of one work cycle
     * @param int|null $seconds
     *
     * @return null
     */
    abstract public function setWorkTimeout(int $seconds = null);

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param bool $triggerError
     */
    public function setTriggerErrorOnError(bool $triggerError)
    {
        $this->triggerErrorOnError = $triggerError;
    }

    /**
     * @param string $message
     */
    public function logInfo(string $message)
    {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message)
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }

    /**
     * @param string $message
     */
    public function logError(string $message)
    {
        if ($this->logger) {
            $this->logger->error($message);
        }

        if ($this->triggerErrorOnError) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
