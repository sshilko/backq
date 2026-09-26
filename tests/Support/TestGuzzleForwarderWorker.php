<?php

namespace BackQ\Tests\Support;

use BackQ\Worker\GuzzleForwarder;

/**
 * Concrete forwarder worker, the worker itself is abstract.
 *
 * The queue name is the one the forwarder publisher of the examples pushes to.
 */
class TestGuzzleForwarderWorker extends GuzzleForwarder
{

    protected string $queueName = 'guzzle_queue';
}
