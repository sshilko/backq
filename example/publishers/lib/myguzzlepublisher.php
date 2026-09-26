<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

use BackQ\Publisher\Guzzle;

final class MyGuzzlePublisher extends Guzzle
{
    protected string $queueName = 'guzzle_queue';
}
