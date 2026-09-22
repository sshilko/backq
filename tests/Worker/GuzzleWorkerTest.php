<?php

namespace BackQ\Tests\Worker;

use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Guzzle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GuzzleWorkerTest extends TestCase
{
    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [11, 'not-a-guzzle-message'];

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 11], $adapter->calls);
        $this->assertContains('disconnect', $adapter->calls);
    }
}
