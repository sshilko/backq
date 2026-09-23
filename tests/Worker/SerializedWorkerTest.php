<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Serialized;
use BackQ\Tests\Support\NoopMessage;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestPublisher;
use BackQ\Worker\Serialized as SerializedWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SerializedWorkerTest extends TestCase
{

    private TestAdapter $adapter;

    public function testRepublishesOriginalMessage(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        $publisher                = new TestPublisher($publishAdapter);
        $publisher->setQueueName('serialized');
        TestPublisher::bindShared($publishAdapter);

        try {
            $serialized = new Serialized(new NoopMessage(), $publisher, ['jobttr' => 9]);
            $this->adapter->pickTaskResult = [3, serialize($serialized)];

            $worker = new SerializedWorker($this->adapter);
            $worker->setQueueName('serialized');
            $worker->setLogger(new NullLogger());
            $worker->setTriggerErrorOnError(false);
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains('connect', $publishAdapter->calls);
        $putCall = $publishAdapter->calls[array_key_last($publishAdapter->calls)];
        $this->assertSame('putTask', $putCall[0]);
        $this->assertSame(['jobttr' => 9], $putCall[2]);
        $this->assertContains(['afterWorkSuccess', 3], $this->adapter->calls);
    }

    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [4, 'garbage'];

        $worker = new SerializedWorker($this->adapter);
        $worker->setQueueName('serialized');
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 4], $this->adapter->calls);
    }

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
    }
}
