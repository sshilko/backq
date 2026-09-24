<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Publisher\AbstractPublisher;

/**
 * Publisher with a no-argument constructor so getInstance() can build it.
 */
class NoArgTestPublisher extends AbstractPublisher
{

    protected function setupAdapter(): AbstractAdapter
    {
        return new TestAdapter();
    }
}
