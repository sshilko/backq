<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\Redis;

use Illuminate\Queue\RedisQueue;

class Queue extends RedisQueue
{

    /**
     * The expiration time of a job.
     * This option specifies how many seconds the queue connection should wait before
     * retrying a job that is being processed
     *
     * Pending job will be released back onto the queue if it has
     * been in processing for >=N seconds without being deleted (successful execution = delete())
     *
     * @see https://laravel.com/docs/5.7/queues#retrying-failed-jobs
     */
    protected ?int $retryAfter = null;

    /**
     * The maximum number of seconds to block for a job.
     *
     */
    protected ?int $blockFor = null;

    /**
     * @param int|null $seconds
     */
    public function setBlockFor(?int $seconds): void
    {
        $this->blockFor = $seconds;
    }
}
