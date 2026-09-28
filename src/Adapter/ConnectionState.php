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

/**
 * Where the Redis adapter is in its connection state machine
 *
 * The int values of Nothing, BindWrite and BindRead are the values of the STATE_* constants
 * the Redis adapter used to expose, kept so that existing integer comparisons keep working.
 * Connected is the fourth state and is new: the adapter answers connect() by asking the
 * server, so "there is a socket" is a fact of its own and no longer the same fact as
 * "a queue is bound".
 */
enum ConnectionState: int
{
    case Nothing   = 0;
    case BindWrite = 1;
    case BindRead  = 2;

    /**
     * The server answered, and this adapter has not been told a role yet
     */
    case Connected = 3;

    /**
     * Is a queue bound, in either direction
     */
    public function isBound(): bool
    {
        return self::BindRead === $this || self::BindWrite === $this;
    }
}
