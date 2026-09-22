<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Closure;
use BackQ\Tests\Support\Flag;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Closure as ClosureWorker;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ClosureWorkerTest extends TestCase
{
    private TestAdapter $adapter;

    protected function setUp(): void
    {
        Flag::$value       = false;
        $this->adapter     = new TestAdapter();
    }

    private function makeWorker(): ClosureWorker
    {
        $worker = new ClosureWorker($this->adapter);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        return $worker;
    }

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
}