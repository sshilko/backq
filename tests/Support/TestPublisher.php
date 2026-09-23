<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Publisher\AbstractPublisher;

/**
 * TestPublisher allows instantiation with an arbitrary (test) adapter.
 *
 * A shared adapter can be bound so that a serialized/unserialized publisher
 * keeps using the same observable adapter instance.
 */
class TestPublisher extends AbstractPublisher
{

    public AbstractAdapter $testAdapter;

    private static ?AbstractAdapter $sharedAdapter = null;

    public function __construct(AbstractAdapter $adapter)
    {
        $this->testAdapter = $adapter;

        parent::__construct();
    }

    public static function bindShared(?AbstractAdapter $adapter): void
    {
        self::$sharedAdapter = $adapter;
    }

    protected function setupAdapter(): AbstractAdapter
    {
        return self::$sharedAdapter ?? $this->testAdapter;
    }
}
