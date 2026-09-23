<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Tests\Support\TestAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbstractAdapterTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame('jobttr', AbstractAdapter::PARAM_JOBTTR);
        $this->assertSame('readywait', AbstractAdapter::PARAM_READYWAIT);
        $this->assertSame(60, AbstractAdapter::JOBTTR_DEFAULT);
    }

    public function testLogInfoForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('hello');

        $adapter = new TestAdapter();
        $adapter->setLogger($logger);
        $adapter->logInfo('hello');
    }

    public function testLogDebugForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with('trace');

        $adapter = new TestAdapter();
        $adapter->setLogger($logger);
        $adapter->logDebug('trace');
    }

    public function testLogErrorForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('failure');

        $adapter = new TestAdapter();
        $adapter->setLogger($logger);
        $adapter->setTriggerErrorOnError(false);
        $adapter->logError('failure');
    }

    public function testLogErrorTriggersWarningWhenEnabled(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        }, E_USER_WARNING);

        try {
            $adapter = new TestAdapter();
            $adapter->logError('warning');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($triggered);
    }

    public function testLogErrorDoesNotTriggerWhenDisabled(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        }, E_USER_WARNING);

        try {
            $adapter = new TestAdapter();
            $adapter->setTriggerErrorOnError(false);
            $adapter->logError('warning');
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
