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

use BackQ\Adapter\Beanstalk\Connection;
use Override;

/**
 * Beanstalk protocol adapter
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

    /**
     * A persistent connection belongs to the next worker, so closing it is a noop
     */
    #[Override]
    public function disconnect(): bool
    {
        if ($this->persistentConnection) {
            return true;
        }

        return parent::disconnect();
    }

    /**
     * Whatever connection the caller asked for, this adapter makes it persistent
     */
    #[Override]
    protected function connection(Connection $connection): Connection
    {
        $this->persistentConnection = true;

        return $connection->asPersistent();
    }
}
