<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Publisher\AbstractPublisher;

/**
 * Publisher that does not restore its adapter on unserialize, the behaviour of
 * a publisher that never travels through a queue.
 */
class PlainTestPublisher extends AbstractPublisher
{

    protected string $queueName = 'plain';

    public function __construct(AbstractAdapter $adapter)
    {
        parent::__construct($adapter);
    }
}
