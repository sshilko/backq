<?php

namespace BackQ\Adapter\Redis;

class Queue extends \Illuminate\Queue\RedisQueue
{
    /**
     * The expiration time of a job.
     *
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
