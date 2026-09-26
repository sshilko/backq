<?php

namespace BackQ\Tests\Publisher;

use BackQ\Publisher\AbstractPublisher;
use BackQ\Publisher\Closure;
use BackQ\Publisher\Guzzle;
use BackQ\Publisher\Process;
use BackQ\Publisher\Serialized;
use BackQ\Tests\Support\TestAdapter;
use PHPUnit\Framework\TestCase;
use Throwable;
use function end;
use function serialize;

/**
 * The publishers of the root namespace only pick the queue they publish to,
 * everything they do with a job is inherited from AbstractPublisher
 */
class DefaultQueueNameTest extends TestCase
{
    public function testEveryPublisherHasItsOwnDefaultQueueName(): void
    {
        foreach ($this->publishers(new TestAdapter()) as $name => $publisher) {
            $this->assertInstanceOf(AbstractPublisher::class, $publisher);
            $this->assertSame($name, $publisher->getQueueName());
        }
    }

    public function testStartBindsTheDefaultQueueName(): void
    {
        $adapter = new TestAdapter();
        foreach ($this->publishers($adapter) as $name => $publisher) {
            $adapter->calls = [];

            $this->assertTrue($publisher->start());
            $this->assertContains(['bindWrite', $name], $adapter->calls);
        }
    }

    public function testTheDefaultQueueNameCanBeOverridden(): void
    {
        foreach ($this->publishers(new TestAdapter()) as $publisher) {
            $publisher->setQueueName('other');

            $this->assertSame('other', $publisher->getQueueName());
        }
    }

    public function testHasWorkersAsksAboutTheDefaultQueueName(): void
    {
        $adapter                    = new TestAdapter();
        $adapter->hasWorkersResult = true;

        foreach ($this->publishers($adapter) as $name => $publisher) {
            $adapter->calls = [];

            $this->assertTrue($publisher->hasWorkers());
            $this->assertContains(['hasWorkers', $name], $adapter->calls);
        }
    }

    public function testPublishIsDelegatedToTheAdapter(): void
    {
        $adapter = new TestAdapter();

        foreach ($this->publishers($adapter) as $publisher) {
            $adapter->calls        = [];
            $adapter->putTaskResult = 'job-1';
            $publisher->start();

            $this->assertSame('job-1', $publisher->publish(['job' => 1]));
            $this->assertSame(['putTask', serialize(['job' => 1]), ['readyWait' => 0]], end($adapter->calls));
        }
    }

    public function testPublishFailsBeforeTheQueueIsBound(): void
    {
        foreach ($this->publishers(new TestAdapter()) as $publisher) {
            $this->assertInstanceOf(Throwable::class, $publisher->publish(['job' => 1]));
        }
    }

    /**
     * The four publishers, keyed by the queue name they default to
     *
     * @param TestAdapter $adapter the adapter every publisher is built with
     *
     * @return array<string, AbstractPublisher>
     */
    private function publishers(TestAdapter $adapter): array
    {
        return [
            'closure' => new class ($adapter) extends Closure {
            },
            'guzzle' => new class ($adapter) extends Guzzle {
            },
            'mydynamodbtablenameandsqsqueuename' => new class ($adapter) extends Serialized {
            },
            'process' => new class ($adapter) extends Process {
            },
        ];
    }
}
