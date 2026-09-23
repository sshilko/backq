<?php

namespace BackQ\Tests\Worker;

use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;
use function restore_error_handler;
use function set_error_handler;
use const E_USER_WARNING;

class AbstractWorkerTest extends TestCase
{

    private TestAdapter $adapter;

    public function testGetSetQueueName(): void
    {
        $worker = $this->makeWorker();
        $worker->setQueueName('foo');

        $this->assertSame('foo', $worker->getQueueName());
    }

    public function testSetWorkTimeoutAcceptsNull(): void
    {
        $worker = $this->makeWorker();
        $worker->setWorkTimeout(null);

        $this->assertNull($worker->workTimeout);
    }

    public function testStartPropagatesTimeoutAndConnects(): void
    {
        $worker = $this->makeWorker();
        $worker->setWorkTimeout(7);

        $this->assertTrue($worker->doStart());

        $this->assertContains(['setWorkTimeout', 7], $this->adapter->calls);
        $this->assertContains('connect', $this->adapter->calls);
        $this->assertContains(['bindRead', 'testqueue'], $this->adapter->calls);
    }

    public function testStartFailureWhenConnectFails(): void
    {
        $this->adapter->connectResult = false;
        $worker = $this->makeWorker();

        $this->assertFalse($worker->doStart());
    }

    public function testStartFailureWhenBindReadFails(): void
    {
        $this->adapter->bindReadResult = false;
        $worker = $this->makeWorker();

        $this->assertFalse($worker->doStart());
        $this->assertContains('connect', $this->adapter->calls);
    }

    public function testFinishReturnsFalseWhenNotStarted(): void
    {
        $worker = $this->makeWorker();

        $this->assertFalse($worker->doFinish());
    }

    public function testWorkAcknowledgesSuccess(): void
    {
        $this->adapter->pickTaskResult = [42, 'payload'];
        $this->adapter->afterWorkSuccessResult = true;
        $worker = $this->makeWorker();
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains([42, 'payload'], $worker->yields);
        $this->assertContains(['afterWorkSuccess', 42], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 42], $this->adapter->calls);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testWorkAcknowledgesFailure(): void
    {
        $this->adapter->pickTaskResult = [42, 'payload'];
        $this->adapter->afterWorkFailedResult = true;
        $worker = $this->makeWorker([false]);
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkFailed', 42], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 42], $this->adapter->calls);
    }

    public function testWorkThrowsWhenNoJobAndNoTimeout(): void
    {
        $this->adapter->pickTaskResult = false;
        $worker = $this->makeWorker();
        $worker->workTimeout = 0;

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('Worker failed to fetch new job');

        $worker->run();
    }

    public function testLogErrorTriggerSuppressedViaSetter(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        }, E_USER_WARNING);

        try {
            $worker = $this->makeWorker();
            $worker->setTriggerErrorOnError(false);
            $worker->logError('something failed');
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
    }

    private function makeWorker(array $responses = [true]): TestWorker
    {
        $worker = new TestWorker($this->adapter, $responses);
        $worker->setLogger(new NullLogger());

        return $worker;
    }
}
