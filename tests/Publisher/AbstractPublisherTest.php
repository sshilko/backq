<?php

namespace BackQ\Tests\Publisher;

use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestPublisher;
use PHPUnit\Framework\TestCase;

class AbstractPublisherTest extends TestCase
{
    private TestAdapter $adapter;
    private TestPublisher $publisher;

    protected function setUp(): void
    {
        $this->adapter   = new TestAdapter();
        $this->publisher = new TestPublisher($this->adapter);
        $this->publisher->setQueueName('testqueue');
    }

    public function testGetSetQueueName(): void
    {
        $this->assertSame('testqueue', $this->publisher->getQueueName());

        $this->publisher->setQueueName('other');
        $this->assertSame('other', $this->publisher->getQueueName());
    }

    public function testPublishBeforeStartReturnsFalse(): void
    {
        $this->assertFalse($this->publisher->publish(['job' => 1]));
    }

    public function testStartConnectsAndBindsWrite(): void
    {
        $this->assertTrue($this->publisher->start());

        $this->assertContains('connect', $this->adapter->calls);
        $this->assertContains(['bindWrite', 'testqueue'], $this->adapter->calls);
    }

    public function testStartFailureWhenConnectFails(): void
    {
        $this->adapter->connectResult = false;

        $this->assertFalse($this->publisher->start());
        $this->assertSame(false, $this->publisher->start());
    }

    public function testPublishDelegatesToAdapter(): void
    {
        $this->adapter->putTaskResult = 'job-1';
        $this->publisher->start();

        $result = $this->publisher->publish(['job' => 1], ['readywait' => 4]);

        $this->assertSame('job-1', $result);

        $putCall = end($this->adapter->calls);
        $this->assertSame([
            'putTask',
            serialize(['job' => 1]),
            ['readywait' => 4],
        ], $putCall);
    }

    public function testReadyPingsOnlyWhenBound(): void
    {
        $this->assertNull($this->publisher->ready());

        $this->publisher->start();
        $this->assertTrue($this->publisher->ready());
        $this->assertContains(['ping', true], $this->adapter->calls);
    }

    public function testHasWorkersDelegatesToAdapter(): void
    {
        $this->adapter->hasWorkersResult = true;

        $this->assertTrue($this->publisher->hasWorkers());
        $this->assertContains(['hasWorkers', 'testqueue'], $this->adapter->calls);
    }

    public function testFinishDisconnects(): void
    {
        $this->publisher->start();

        $this->assertTrue($this->publisher->finish());
        $this->assertContains('disconnect', $this->adapter->calls);
        $this->assertFalse($this->publisher->finish());
    }

    public function testSerializationRoundTrip(): void
    {
        $this->publisher->start();
        $serialized = serialize($this->publisher);
        $this->assertContains('disconnect', $this->adapter->calls);

        /** @var TestPublisher $restored */
        $restored = unserialize($serialized);

        $this->assertInstanceOf(TestPublisher::class, $restored);
        $this->assertSame('testqueue', $restored->getQueueName());
    }
}