<?php

use BackQ\Publisher\AbstractPublisher;

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

class GuzzleForwarder extends AbstractPublisher
{
    protected string $queueName = 'guzzle_queue';
}
