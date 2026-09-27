<?php

namespace BackQ\Tests\Worker;

use BackQ\Tests\Support\ConfigurableWorker;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\SignaledWorker;
use BackQ\Tests\Support\SleepingPickAdapter;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestWorker;
use BackQ\Tests\Support\ThrowingPickAdapter;
use BackQ\Worker\AbstractWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
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

    public function testTheWorkTimeoutDefaultsToSixtySeconds(): void
    {
        $this->assertSame(60, AbstractWorker::DEFAULT_WORK_TIMEOUT);

        $worker = $this->makeWorker();
        $worker->doStart();

        $this->assertSame(60, $worker->workTimeout);
        $this->assertContains(['setWorkTimeout', 60], $this->adapter->calls);
    }

    public function testTheConstructorTakesTheWorkTimeout(): void
    {
        $worker = $this->makeWorker(workTimeout: 7);
        $worker->doStart();

        $this->assertSame(7, $worker->workTimeout);
        $this->assertContains(['setWorkTimeout', 7], $this->adapter->calls);
    }

    public function testTheConstructorTakesNoWorkTimeout(): void
    {
        $worker = $this->makeWorker(workTimeout: null);
        $worker->doStart();

        $this->assertNull($worker->workTimeout);
        $this->assertContains(['setWorkTimeout', null], $this->adapter->calls);
    }

    public function testTheDeprecatedSetterIsStillFunctional(): void
    {
        $worker = $this->makeWorker();
        $worker->setWorkTimeout(3);
        $worker->doStart();

        $this->assertSame(3, $worker->workTimeout);
        $this->assertContains(['setWorkTimeout', 3], $this->adapter->calls);
    }

    public function testTheDeprecatedSetterAcceptsNull(): void
    {
        $worker = $this->makeWorker();
        $worker->setWorkTimeout(null);

        $this->assertNull($worker->workTimeout);
    }

    public function testStartPropagatesTimeoutAndConnects(): void
    {
        $worker = $this->makeWorker(workTimeout: 7);

        $this->assertTrue($worker->doStart());

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

    public function testWorkIdlesWhenNoJobAndNoTimeout(): void
    {
        $adapter                = new SleepingPickAdapter();
        $adapter->pickTaskResult = false;
        $logger                 = new RecordingLogger();
        $worker                 = new TestWorker($adapter, null);
        $worker->setLogger($logger);
        $worker->setIdleTimeout(1);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringNotContainsString('Worker failed to fetch new job', $wholeLog);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testHeartbeatStyleIdleWithoutTimeoutDoesNotKillWorker(): void
    {
        $adapter = new SleepingPickAdapter();
        $logger  = new RecordingLogger();
        $worker  = new ConfigurableWorker($adapter, null);
        $worker->setLogger($logger);
        $worker->setIdleTimeout(1);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringNotContainsString('Worker failed to fetch new job', $wholeLog);
    }

    public function testLogErrorForwardsToLogger(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->makeWorker();
        $worker->setLogger($logger);
        $worker->logError('attention required');

        $this->assertContains('error', array_column($logger->records, 0));
        $this->assertContains('attention required', array_column($logger->records, 1));
    }

    public function testSetIdleTimeout(): void
    {
        $worker = $this->makeWorker();
        $worker->setIdleTimeout(7);

        $property = new \ReflectionProperty($worker, 'idleTimeout');
        $this->assertSame(7, $property->getValue($worker));
    }

    public function testWorkReturnsWithoutBinding(): void
    {
        $worker = $this->makeWorker();

        $this->assertSame([], iterator_to_array($worker->doWork()));
    }

    public function testTheWorkTimeoutIsLoweredBelowTheIdleTimeout(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->makeWorker();
        $worker->setLogger($logger);
        $worker->setIdleTimeout(5);

        $worker->doStart();

        /**
         * The adapter and the work loop have to agree, and the configured value is
         * left alone: only what the worker works with is lowered
         */
        $this->assertContains(['setWorkTimeout', 4], $this->adapter->calls);
        $this->assertSame(60, $worker->workTimeout);
        $this->assertStringContainsString('Work timeout 60 lowered to 4', $this->wholeLog($logger));
    }

    public function testTheWorkTimeoutIsNotLoweredWhenItFitsBelowTheIdleTimeout(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->makeWorker(workTimeout: 3);
        $worker->setLogger($logger);
        $worker->setIdleTimeout(5);

        $worker->doStart();

        $this->assertContains(['setWorkTimeout', 3], $this->adapter->calls);
        $this->assertStringNotContainsString('lowered', $this->wholeLog($logger));
    }

    public function testNoWorkTimeoutIsNotLowered(): void
    {
        $worker = $this->makeWorker(workTimeout: null);
        $worker->setIdleTimeout(5);

        $worker->doStart();

        $this->assertContains(['setWorkTimeout', null], $this->adapter->calls);
    }

    public function testTerminationRequestedStopsLoop(): void
    {
        $this->adapter->pickTaskResult = [42, 'payload'];
        $logger                        = new RecordingLogger();
        $worker                        = new SignaledWorker($this->adapter);
        $worker->setLogger($logger);
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
        $worker            = new TestWorker($adapter, 1);
        $worker->setLogger($logger);
        $worker->setRestartThreshold(0);
        $worker->setIdleTimeout(2);

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
        $worker                 = new ConfigurableWorker($adapter, 1);
        $worker->idleTimeoutResult = false;
        $worker->setLogger($logger);
        $worker->setRestartThreshold(4);
        $worker->setIdleTimeout(2);

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

    private function makeWorker(
        array $responses = [true],
        ?int $workTimeout = AbstractWorker::DEFAULT_WORK_TIMEOUT,
    ): TestWorker {
        $worker = new TestWorker($this->adapter, $workTimeout, $responses);
        $worker->setLogger(new NullLogger());

        return $worker;
    }

    private function wholeLog(RecordingLogger $logger): string
    {
        return implode("\n", array_column($logger->records, 1));
    }
}
