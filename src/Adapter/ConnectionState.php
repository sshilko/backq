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

/**
 * Internal connection state machine shared by the Redis and Nsq adapters.
 *
 * Backed by the integer values of the legacy STATE_* constants so that
 * existing integer comparisons keep working.
 */
enum ConnectionState: int
{
    case Nothing   = 0;
    case BindWrite = 1;
    case BindRead  = 2;
}
