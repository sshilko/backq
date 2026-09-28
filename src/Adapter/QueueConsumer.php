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

/**
 * The half of an adapter a worker programs to
 *
 * Four methods, and nothing a worker never calls. A worker takes a QueueConsumer, so an
 * adapter that only consumes can be written against this interface instead of against the
 * eleven-method AbstractAdapter.
 *
 * connect() and disconnect() are not here on purpose: both consumers need them, so no
 * worker can be driven by this interface alone. An implementation handed to a worker has
 * to offer those two as well; AbstractAdapter does.
 */
interface QueueConsumer
{
    /**
     * Subscribe to the queue the worker takes jobs from
     *
     * @param string $queue
     */
    public function bindRead(string $queue): bool;

    /**
     * Get a job to process, or false when none arrived within the timeout
     *
     * A false pick is an idle cycle, so an adapter must not answer false for a transport
     * failure it knows about; it raises that instead.
     *
     * @param int|null $timeout seconds
     *
     * @return bool|array [id, payload]
     */
    public function pickTask(?int $timeout = null): bool|array;

    /**
     * Acknowledge the job after it was processed
     *
     * @param string|null $workId the id pickTask() reported, as a string
     */
    public function afterWorkSuccess(?string $workId): bool;

    /**
     * Acknowledge the job after processing it failed
     *
     * @param string|null $workId the id pickTask() reported, as a string
     */
    public function afterWorkFailed(?string $workId): bool;
}
