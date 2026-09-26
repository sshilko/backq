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
use Stringable;
use Throwable;

abstract class AbstractAdapter
{
    public const int JOBTTR_DEFAULT = 60;

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
     * An adapter may widen this signature by appending its own optional parameters. It may not
     * append a required one, narrow a parameter, or add to the return union: PHP rejects all
     * three as an incompatible declaration.
     *
     * A transport or storage failure is returned as the Throwable rather than raised, so the
     * caller can log it and keep the original type, message and previous chain. An invalid
     * argument is a bug in the caller and is raised.
     *
     * @param string|Stringable $body
     *
     * @return string|Throwable|null the job id, null when this adapter reports no ids,
     *                              the failure otherwise
     */
    abstract public function putTask(string|Stringable $body): null|string|Throwable;

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
    }
}
