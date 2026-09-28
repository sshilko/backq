<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\Beanstalk;

use InvalidArgumentException;

/**
 * Where the adapter opens its socket
 *
 * These are the four settings connect() used to take as positional arguments, plus the
 * stream context IO\StreamIO has always accepted. Passing a context is what switches the
 * stream from `tcp://` to `tls://`, and the client in this repository could do it while
 * no adapter could ask for it.
 */
final readonly class Connection
{
    public const int PORT_LOWER = 1;
    public const int PORT_UPPER = 65535;

    /**
     * @param string $host server the adapter connects to
     * @param int $port TCP port, 1…65535
     * @param int $timeout seconds to wait for a connection, 0 lets the client use its own
     * @param bool $persistent whether the socket outlives the process
     * @param mixed $context stream context, a resource from stream_context_create(), or null
     *
     * @throws InvalidArgumentException when the port is outside the range TCP accepts
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 11300,
        public int $timeout = 1,
        public bool $persistent = false,
        public mixed $context = null,
    ) {
        if ($port < self::PORT_LOWER || $port > self::PORT_UPPER) {
            throw new InvalidArgumentException(
                'port must be between ' . self::PORT_LOWER . ' and ' . self::PORT_UPPER . ', got ' . $port
            );
        }
    }

    /**
     * The same connection, persistent
     *
     * PersistentBeanstalk asks the connection it was given to persist rather than
     * overriding connect(), so the socket outlives the worker and disconnect() is a noop.
     */
    public function asPersistent(): self
    {
        return new self($this->host, $this->port, $this->timeout, true, $this->context);
    }
}
