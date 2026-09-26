<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Tests\Support\TestAdapter;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbstractAdapterTest extends TestCase
{
    public function testJobTtrDefaultConstant(): void
    {
        $this->assertSame(60, AbstractAdapter::JOBTTR_DEFAULT);
    }

    public function testStringBodyIsAcceptedByTheStringableParameter(): void
    {
        // A bare Stringable would raise a TypeError here, because a raw string does not
        // implement it. publish() feeds serialize() output, which is a string.
        $this->assertNull((new TestAdapter())->putTask('a plain string'));
    }

    public function testPutTaskRejectsRetiredKeyName(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $jobttr');

        (new TestAdapter())->putTask('body', jobttr: 5);
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
        $adapter->logError('failure');
    }

    public function testLogErrorIsSilentWithoutLogger(): void
    {
        $adapter = new TestAdapter();
        $adapter->logError('no logger attached');
        $this->expectNotToPerformAssertions();
    }
}
