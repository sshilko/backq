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

use Override;

/**
 * Beanstalk protocol adapter
 * @phpcs:disable
 *
 * @see https://raw.githubusercontent.com/kr/beanstalkd/master/doc/protocol.txt
 */
class PersistentBeanstalk extends Beanstalk
{
    protected bool $persistentConnection = false;

    public function __destruct()
    {
        $this->disconnect();
    }

    #[Override]
    public function connect(string $host = '127.0.0.1', int $port = 11300, int $timeout = 1, bool $persistent = true, $logger = null): bool
    {
        $this->persistentConnection = $persistent;

        return parent::connect($host, $port, $timeout, $persistent, ($logger ? $logger : $this));
    }

    #[Override]
    public function disconnect(): bool
    {
        if ($this->persistentConnection) {
            return true;
        } else {
            return parent::disconnect();
        }
    }
}
