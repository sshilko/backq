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

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Queue\Connectors\RedisConnector;

class Connector extends RedisConnector
{
   /**
     * Create a new Redis queue connector instance.
     *
     * @param Redis $redis
     * @param  string|null  $connection
     * @return void
     */
    public function __construct(Redis $redis, $connection = null)
    {
        parent::__construct($redis, $connection);
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     */
    public function connect(array $config): Queue
    {
        return new Queue(
            $this->redis,
            $config['queue'],
            $config['connection']  ?? $this->connection,
            $config['retry_after'] ?? null,
            $config['block_for']   ?? null
        );
    }
}
