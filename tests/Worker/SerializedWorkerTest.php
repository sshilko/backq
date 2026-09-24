<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Serialized;
use BackQ\Tests\Support\ExpiredSerializedMessage;
use BackQ\Tests\Support\NoopMessage;
use BackQ\Tests\Support\NotReadySerializedMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestPublisher;
use BackQ\Tests\Support\ThrowingPutTaskAdapter;
use BackQ\Worker\Serialized as SerializedWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use function array_column;
use function array_key_last;
use function implode;
use function is_array;
use function serialize;

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

        $this->runWorker(4)->run();

        $this->assertContains(['afterWorkSuccess', 4], $this->adapter->calls);
    }

    public function testEmptyPayloadIsSkipped(): void
    {
        $this->adapter->pickTaskResult = false;

        $worker = $this->runWorker(5);
        $worker->run();

        $this->assertSame(
            [
                ['setWorkTimeout', 5],
                'connect',
                ['bindRead', 'serialized'],
                ['pickTask', null],
                'disconnect',
            ],
            $this->adapter->calls
        );
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [6, 12345];

        $logger = new RecordingLogger();
        $this->runWorker(6, $logger)->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Worker does not support payload of: integer', $wholeLog);
        $this->assertContains(['afterWorkSuccess', 6], $this->adapter->calls);
    }

    public function testDefersNotReadyMessage(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $this->adapter->pickTaskResult = [7, serialize(new NotReadySerializedMessage($publisher))];
            $this->adapter->afterWorkFailedResult = true;

            $this->runWorker(7)->run();
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains(['afterWorkFailed', 7], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 7], $this->adapter->calls);
    }

    public function testDiscardsExpiredMessageAsSuccess(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $this->adapter->pickTaskResult = [8, serialize(new ExpiredSerializedMessage($publisher))];
            $this->adapter->afterWorkFailedResult = true;

            $this->runWorker(8)->run();
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains(['afterWorkSuccess', 8], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 8], $this->adapter->calls);
    }

    public function testReportsMissingOriginalMessage(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $serialized = new Serialized(new NoopMessage(), $publisher);
            $property   = new ReflectionProperty(Serialized::class, 'message');
            $property->setAccessible(true);
            $property->setValue($serialized, null);
            $this->adapter->pickTaskResult = [9, serialize($serialized)];

            $logger = new RecordingLogger();
            $this->runWorker(9, $logger, $serialized)->run();

            $wholeLog = implode("\n", array_column($logger->records, 1));
            $this->assertStringContainsString('Missing original message', $wholeLog);
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains(['afterWorkSuccess', 9], $this->adapter->calls);
    }

    public function testReportsMissingOriginalPublisher(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $serialized = new Serialized(new NoopMessage(), $publisher);
            $property   = new ReflectionProperty(Serialized::class, 'publisher');
            $property->setAccessible(true);
            $property->setValue($serialized, null);
            $this->adapter->pickTaskResult = [10, serialize($serialized)];

            $logger = new RecordingLogger();
            $this->runWorker(10, $logger, $serialized)->run();

            $wholeLog = implode("\n", array_column($logger->records, 1));
            $this->assertStringContainsString('Missing original publisher', $wholeLog);
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains(['afterWorkSuccess', 10], $this->adapter->calls);
    }

    public function testReportsDoNotPublishWhenPublisherStartFails(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->connectResult = false;
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $serialized = new Serialized(new NoopMessage(), $publisher);
            $this->adapter->pickTaskResult = [11, serialize($serialized)];
            $this->adapter->afterWorkFailedResult = true;

            $this->runWorker(11)->run();
        } finally {
            TestPublisher::bindShared(null);
        }

        foreach ($publishAdapter->calls as $call) {
            $this->assertNotSame('putTask', is_array($call) ? $call[0] : $call);
        }
        $this->assertContains(['afterWorkFailed', 11], $this->adapter->calls);
    }

    public function testReportsDispatchFailure(): void
    {
        $publishAdapter = new ThrowingPutTaskAdapter();
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $serialized = new Serialized(new NoopMessage(), $publisher);
            $this->adapter->pickTaskResult = [12, serialize($serialized)];
            $this->adapter->afterWorkFailedResult = true;

            $logger = new RecordingLogger();
            $this->runWorker(12, $logger, $serialized)->run();

            $wholeLog = implode("\n", array_column($logger->records, 1));
            $this->assertStringContainsString('putTask exploded', $wholeLog);
            $this->assertContains(['afterWorkFailed', 12], $this->adapter->calls);
        } finally {
            TestPublisher::bindShared(null);
        }
    }

    public function testAckFailureTriggersOuterCatch(): void
    {
        $publishAdapter           = new TestAdapter();
        $publishAdapter->putTaskResult = 'republished-id';
        $this->adapter->afterWorkSuccessResult = false;
        TestPublisher::bindShared($publishAdapter);

        try {
            $publisher = new TestPublisher($publishAdapter);
            $publisher->setQueueName('serialized');
            $serialized = new Serialized(new NoopMessage(), $publisher);
            $this->adapter->pickTaskResult = [13, serialize($serialized)];

            $logger = new RecordingLogger();
            $this->runWorker(13, $logger, $serialized)->run();

            $wholeLog = implode("\n", array_column($logger->records, 1));
            $this->assertStringContainsString('Worker failed to acknowledge job result', $wholeLog);
        } finally {
            TestPublisher::bindShared(null);
        }

        $this->assertContains('disconnect', $this->adapter->calls);
    }

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
    }

    /**
     * @param int               $id
     * @param RecordingLogger|null $logger
     * @param Serialized|null   $serialized
     */
    private function runWorker(
        int $id,
        RecordingLogger|null $logger = null,
        Serialized|null $serialized = null,
    ): SerializedWorker {
        $logger ??= new RecordingLogger();
        if (null !== $serialized) {
            $this->adapter->pickTaskResult = [$id, serialize($serialized)];
        }

        $worker = new SerializedWorker($this->adapter);
        $worker->setQueueName('serialized');
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        return $worker;
    }
}
