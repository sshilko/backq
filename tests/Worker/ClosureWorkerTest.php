<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Closure;
use BackQ\Tests\Support\ClosureExpiredMessage;
use BackQ\Tests\Support\ClosureNotReadyMessage;
use BackQ\Tests\Support\Flag;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Closure as ClosureWorker;
use BackQ\Worker\Closure\RecoverableException;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use function array_column;
use function implode;
use function serialize;

class ClosureWorkerTest extends TestCase
{

    private TestAdapter $adapter;

    public function testProcessesClosureMessage(): void
    {
        $closure = new SerializableClosure(static function (): void {
            Flag::$value = 'executed';
        });
        $this->adapter->pickTaskResult = [7, serialize(new Closure($closure))];

        $this->makeWorker()->run();

        $this->assertSame('executed', Flag::$value);
        $this->assertContains(['afterWorkSuccess', 7], $this->adapter->calls);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [8, 'not-a-serialized-message'];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkSuccess', 8], $this->adapter->calls);
        $this->assertSame(false, Flag::$value);
    }

    public function testEmptyPayloadIsSkipped(): void
    {
        $this->adapter->pickTaskResult = false;

        $this->makeWorker()->run();

        $this->assertSame(
            [
                ['setWorkTimeout', 5],
                'connect',
                ['bindRead', 'closure'],
                ['pickTask', null],
                'disconnect',
            ],
            $this->adapter->calls
        );
    }

    public function testDefersNotReadyMessage(): void
    {
        $this->adapter->pickTaskResult = [9, serialize(new ClosureNotReadyMessage())];
        $this->adapter->afterWorkFailedResult = true;

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 9], $this->adapter->calls);
        $this->assertSame(false, Flag::$value);
    }

    public function testDiscardsExpiredMessageAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [10, serialize(new ClosureExpiredMessage())];
        $this->adapter->afterWorkFailedResult = true;

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkSuccess', 10], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 10], $this->adapter->calls);
    }

    public function testReportsRecoverableClosureFailure(): void
    {
        $closure = new SerializableClosure(static function (): void {
            throw new RecoverableException('recoverable boom');
        });
        $this->adapter->pickTaskResult = [11, serialize(new Closure($closure))];
        $this->adapter->afterWorkFailedResult = true;

        $logger = new RecordingLogger();
        $this->makeWorker($logger)->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Failed executing closure recoverable boom', $wholeLog);
        $this->assertContains(['afterWorkFailed', 11], $this->adapter->calls);
    }

    public function testReportsGenericClosureFailure(): void
    {
        $closure = new SerializableClosure(static function (): void {
            throw new \RuntimeException('generic boom');
        });
        $this->adapter->pickTaskResult = [12, serialize(new Closure($closure))];

        $logger = new RecordingLogger();
        $this->makeWorker($logger)->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Error executing closure generic boom', $wholeLog);
        $this->assertContains(['afterWorkSuccess', 12], $this->adapter->calls);
    }

    public function testAckFailureTriggersOuterCatch(): void
    {
        $closure = new SerializableClosure(static function (): void {
            Flag::$value = 'executed';
        });
        $this->adapter->pickTaskResult = [13, serialize(new Closure($closure))];
        $this->adapter->afterWorkSuccessResult = false;

        $logger = new RecordingLogger();
        $this->makeWorker($logger)->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Worker failed to acknowledge job result', $wholeLog);
        $this->assertSame('executed', Flag::$value);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    protected function setUp(): void
    {
        Flag::$value       = false;
        $this->adapter     = new TestAdapter();
    }

    private function makeWorker(RecordingLogger|NullLogger $logger = new NullLogger()): ClosureWorker
    {
        $worker = new ClosureWorker($this->adapter);
        $worker->setLogger($logger);
        $worker->setRestartThreshold(1);

        return $worker;
    }
}
