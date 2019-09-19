<?php

namespace BackQ\Adapter\Redis;

class Queue extends \Illuminate\Queue\RedisQueue
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
     * @var int|null
     */
    protected $retryAfter = null;

    /**
     * The maximum number of seconds to block for a job.
     *
     * @var int|null
     */
    protected $blockFor = null;

    /**
     * @param int|null $seconds
     */
    public function setBlockFor(?int $seconds) {
        $this->blockFor = $seconds;
    }
}
