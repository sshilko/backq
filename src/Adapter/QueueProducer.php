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

use Stringable;
use Throwable;

/**
 * The half of an adapter a publisher programs to
 *
 * Four methods, and nothing a publisher never calls. A publisher takes a QueueProducer,
 * so an adapter that only produces can be written against this interface instead of
 * against the eleven-method AbstractAdapter.
 *
 * connect() and disconnect() are not here on purpose: both consumers need them, so no
 * publisher can be driven by this interface alone. An implementation handed to a
 * publisher has to offer those two as well; AbstractAdapter does.
 */
interface QueueProducer
{
    /**
     * Prepare to write to the queue the publisher puts jobs on
     *
     * @param string $queue
     */
    public function bindWrite(string $queue): bool;

    /**
     * Put a job on the queue
     *
     * An implementation may widen this signature by appending its own optional parameters.
     * It may not append a required one, narrow a parameter, or add to the return union:
     * PHP rejects all three as an incompatible declaration.
     *
     * A transport or storage failure is returned as the Throwable rather than raised, so
     * the caller can log it and keep the original type, message and previous chain. An
     * invalid argument is a bug in the caller and is raised.
     *
     * @param string|Stringable $body
     *
     * @return string|Throwable|null the job id, null when this adapter reports no ids,
     *                              the failure otherwise
     */
    public function putTask(string|Stringable $body): null|string|Throwable;

    /**
     * Is there a worker ready to take a job immediately
     *
     * @param string $queue
     */
    public function hasWorkers(string $queue): bool;

    /**
     * Is the connection to the queue still alive
     *
     * @param bool $reconnect try to re-establish a dropped connection
     */
    public function ping(bool $reconnect = true): bool;
}
