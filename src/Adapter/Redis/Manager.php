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

use Illuminate\Redis\RedisManager;

/**
 * Declares the connection-management methods that the parent forwards
 * to the underlying Redis connection via __call.
 */
class Manager extends RedisManager
{
    public function isConnected(): bool
    {
        return (bool) parent::__call('isConnected', []);
    }

    public function disconnect(): void
    {
        parent::__call('disconnect', []);
    }
}
