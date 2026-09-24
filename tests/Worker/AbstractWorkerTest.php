<?php

namespace BackQ\Tests\Worker;

use BackQ\Tests\Support\ConfigurableWorker;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\SignaledWorker;
use BackQ\Tests\Support\SleepingPickAdapter;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestWorker;
use BackQ\Tests\Support\ThrowingPickAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use function restore_error_handler;
use function set_error_handler;
use const E_USER_WARNING;
use const SIGINT;

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

    public function testSetIdleTimeout(): void
    {
        $worker = $this->makeWorker();
        $worker->setIdleTimeout(7);

        $property = new \ReflectionProperty($worker, 'idleTimeout');
        $this->assertSame(7, $property->getValue($worker));
    }

    public function testLogErrorTriggersWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $worker = $this->makeWorker();
            $worker->logError('attention required');
        } finally {
            restore_error_handler();
        }

        $this->assertContains('attention required', $warnings);
    }

    public function testWorkReturnsWithoutBinding(): void
    {
        $worker = $this->makeWorker();

        $this->assertSame([], iterator_to_array($worker->doWork()));
    }

    public function testWorkThrowsWhenIdleTimeoutNotLowerThanPickInterval(): void
    {
        $worker = $this->makeWorker();
        $worker->doStart();
        $worker->setWorkTimeout(5);
        $worker->setIdleTimeout(5);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Time to pick next task cannot be lower than idle timeout');

        iterator_to_array($worker->doWork());
    }

    public function testTerminationRequestedStopsLoop(): void
    {
        $this->adapter->pickTaskResult = [42, 'payload'];
        $logger                        = new RecordingLogger();
        $worker                        = new SignaledWorker($this->adapter);
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(3);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('termination requested', $wholeLog);
        $this->assertContains(['afterWorkSuccess', 42], $this->adapter->calls);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testTerminationRequestedStopsLoopForSigint(): void
    {
        $this->adapter->pickTaskResult = [43, 'payload'];
        $logger                        = new RecordingLogger();
        $worker                        = new SignaledWorker($this->adapter);
        $worker->signal                = SIGINT;
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(3);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('termination requested', $wholeLog);
        $this->assertContains(['afterWorkSuccess', 43], $this->adapter->calls);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testIdleTimeoutBreaksLoop(): void
    {
        $adapter           = new SleepingPickAdapter();
        $adapter->pickTaskResult = false;
        $logger            = new RecordingLogger();
        $worker            = new TestWorker($adapter);
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(0);
        $worker->setIdleTimeout(2);
        $worker->setWorkTimeout(1);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Idle timeout reached, returning job, quitting', $wholeLog);
        $this->assertStringContainsString('onIdleTimeout true', $wholeLog);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testIdleTimeoutContinuesWhenOnIdleTimeoutFalse(): void
    {
        $adapter                = new SleepingPickAdapter();
        $adapter->pickTaskResult = false;
        $logger                 = new RecordingLogger();
        $worker                 = new ConfigurableWorker($adapter);
        $worker->idleTimeoutResult = false;
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(4);
        $worker->setIdleTimeout(2);
        $worker->setWorkTimeout(1);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('onIdleTimeout false', $wholeLog);
        $this->assertStringContainsString('Restart threshold reached, returning job, quitting', $wholeLog);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testRestartThresholdContinuesWhenOnRestartThresholdFalse(): void
    {
        $adapter                       = new ThrowingPickAdapter();
        $adapter->pickTaskResult       = [42, 'payload'];
        $adapter->throwOnPick          = 4;
        $logger                        = new RecordingLogger();
        $worker                        = new ConfigurableWorker($adapter);
        $worker->restartThresholdResult = false;
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(2);

        try {
            $worker->run();
            $this->fail('expected pickTask to throw');
        } catch (RuntimeException $e) {
            $this->assertSame('pick task exploded', $e->getMessage());
        }

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('onRestartThreshold false', $wholeLog);
        $this->assertNotContains('disconnect', $adapter->calls);
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
