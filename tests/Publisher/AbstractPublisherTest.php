<?php

namespace BackQ\Tests\Publisher;

use BackQ\Publisher\AbstractPublisher;
use BackQ\Tests\Support\FailingPutTaskAdapter;
use BackQ\Tests\Support\PlainTestPublisher;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestPublisher;
use BackQ\Tests\Support\ThrowingPutTaskAdapter;
use BadMethodCallException;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function unserialize;
use const E_USER_DEPRECATED;

class AbstractPublisherTest extends TestCase
{

    private TestAdapter $adapter;

    private TestPublisher $publisher;

    public function testGetSetQueueName(): void
    {
        $this->assertSame('testqueue', $this->publisher->getQueueName());

        $this->publisher->setQueueName('other');
        $this->assertSame('other', $this->publisher->getQueueName());
    }

    public function testPublishBeforeStartReturnsThrowable(): void
    {
        $this->assertInstanceOf(Throwable::class, $this->publisher->publish(['job' => 1]));
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

    public function testStartReturnsTrueWhenAlreadyBound(): void
    {
        $this->assertTrue($this->publisher->start());
        $this->adapter->calls = [];

        $this->assertTrue($this->publisher->start());

        $this->assertNotContains('connect', $this->adapter->calls);
    }

    public function testGetInstanceIsDeprecatedAndNoLongerBuildsAPublisher(): void
    {
        $deprecations = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_USER_DEPRECATED
        );

        try {
            TestPublisher::getInstance();
            $this->fail('getInstance() must not build a publisher anymore');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString(
                TestPublisher::class . '::getInstance() is deprecated',
                $e->getMessage()
            );
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('use the constructor instead', $deprecations[0]);
    }

    public function testPublishDelegatesToAdapter(): void
    {
        $this->adapter->putTaskResult = 'job-1';
        $this->publisher->start();

        $result = $this->publisher->publish(['job' => 1], readyWait: 4);

        $this->assertSame('job-1', $result);

        $putCall = end($this->adapter->calls);
        $this->assertSame([
            'putTask',
            serialize(['job' => 1]),
            ['readyWait' => 4],
        ], $putCall);
    }

    public function testPublishAcceptsWidenedArguments(): void
    {
        $this->publisher->start();

        $this->publisher->publish(['job' => 1], readyWait: 4, jobTtr: 30, noSleep: true);

        $putCall = end($this->adapter->calls);
        $this->assertSame('putTask', $putCall[0]);
        $this->assertSame(serialize(['job' => 1]), $putCall[1]);
        // Named arguments carry no order, so each forwarded option is asserted by name
        $this->assertCount(3, $putCall[2]);
        $this->assertSame(4, $putCall[2]['readyWait']);
        $this->assertSame(30, $putCall[2]['jobTtr']);
        $this->assertTrue($putCall[2]['noSleep']);
    }

    public function testPublishRejectsAnOptionsArray(): void
    {
        $this->publisher->start();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('named arguments');

        $this->publisher->publish(['job' => 1], ['readywait' => 4]);
    }

    public function testPublishRejectsAnUnknownArgumentName(): void
    {
        $this->publisher->start();

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $readywait');

        $this->publisher->publish(['job' => 1], readywait: 4);
    }

    public function testPublishReturnsTheThrowableTheAdapterReported(): void
    {
        $failing            = new FailingPutTaskAdapter();
        $publisher          = new TestPublisher($failing);
        $publisher->setQueueName('testqueue');
        $publisher->start();

        $result = $publisher->publish(['job' => 1]);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame('putTask exploded', $result->getMessage());
    }

    public function testPublishLetsTheAdapterThrow(): void
    {
        $publisher          = new TestPublisher(new ThrowingPutTaskAdapter());
        $publisher->setQueueName('testqueue');
        $publisher->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('putTask exploded');

        $publisher->publish(['job' => 1]);
    }

    public function testReadyPingsOnlyWhenBound(): void
    {
        $this->assertFalse($this->publisher->ready());

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

        $restored = unserialize($serialized);
        \assert($restored instanceof TestPublisher);

        $this->assertInstanceOf(TestPublisher::class, $restored);
        $this->assertSame('testqueue', $restored->getQueueName());
    }

    public function testSerializationDropsTheAdapter(): void
    {
        $restored = unserialize(serialize(new PlainTestPublisher($this->adapter)));
        \assert($restored instanceof AbstractPublisher);

        $adapter = new ReflectionProperty(AbstractPublisher::class, 'adapter');
        $this->assertNull($adapter->getValue($restored));
    }

    protected function setUp(): void
    {
        $this->adapter   = new TestAdapter();
        $this->publisher = new TestPublisher($this->adapter);
        $this->publisher->setQueueName('testqueue');
    }
}
