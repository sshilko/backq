<?php

namespace BackQ\Adapter\Redis;

use Illuminate\Contracts\Redis\Factory as Redis;

class Connector extends \Illuminate\Queue\Connectors\RedisConnector
{   /**
     * Create a new Redis queue connector instance.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
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
     * @return \BackQ\Adapter\Redis\Queue
     */
    public function connect(array $config)
    {
        return new Queue(
            $this->redis, $config['queue'],
            $config['connection']  ?? $this->connection,
            $config['retry_after'] ?? null,
            $config['block_for']   ?? null
        );
    }
}
