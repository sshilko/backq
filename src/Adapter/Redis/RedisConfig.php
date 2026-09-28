<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\Redis;

use InvalidArgumentException;

/**
 * How the adapter reaches the Redis server
 *
 * These are the nine settings the adapter used to take as named arguments, plus the queue
 * name connect() needs before there is a binding, kept as one object so they are validated
 * once, at the call site, instead of deeper down where a connector reports "Connection
 * refused" for a port that never existed. The property names follow this repository's
 * style, so the mapping onto the illuminate/redis keys `read_timeout`, `persistent_id` and
 * `database` is only in Redis::ensureConnected().
 */
readonly class RedisConfig
{
    public const int PORT_LOWER  = 1;
    public const int PORT_UPPER  = 65535;
    public const int TIMEOUT_MIN = 1;
    public const int DATABASE_MIN = 0;

    /**
     * @param string $host server the adapter connects to
     * @param int $port TCP port, 1…65535
     * @param bool $persistent whether the connection outlives the process
     * @param ?string $persistentId identity of a persistent connection
     * @param ?string $prefix prepended to every key
     * @param int $timeout seconds to wait for a connection
     * @param int $readTimeout seconds to wait for a reply, and the ceiling for a blocking pop
     * @param int $databaseId logical database number
     * @param string $queueName queue the adapter is bound to until bindRead()/bindWrite() says otherwise
     * @param ?string $authPassword password for AUTH
     *
     * @throws InvalidArgumentException when a setting is outside the range the server accepts
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) the ten settings are the point of the object
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public bool $persistent = false,
        public ?string $persistentId = null,
        public ?string $prefix = null,
        public int $timeout = 10,
        public int $readTimeout = 10,
        public int $databaseId = 0,
        public ?string $authPassword = null,
        public string $queueName = 'default',
    ) {
        if ($port < self::PORT_LOWER || $port > self::PORT_UPPER) {
            throw new InvalidArgumentException(
                'port must be between ' . self::PORT_LOWER . ' and ' . self::PORT_UPPER . ', got ' . $port
            );
        }
        if ($timeout < self::TIMEOUT_MIN) {
            throw new InvalidArgumentException(
                'timeout must be at least ' . self::TIMEOUT_MIN . ' second, got ' . $timeout
            );
        }
        if ($readTimeout < self::TIMEOUT_MIN) {
            throw new InvalidArgumentException(
                'readTimeout must be at least ' . self::TIMEOUT_MIN . ' second, got ' . $readTimeout
            );
        }
        if ($databaseId < self::DATABASE_MIN) {
            throw new InvalidArgumentException(
                'databaseId must be at least ' . self::DATABASE_MIN . ', got ' . $databaseId
            );
        }
    }
}
