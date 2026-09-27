<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Beanstalk;
use BackQ\Tests\Support\TestAdapter;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class AbstractAdapterTest extends TestCase
{
    public function testBaseClassDeclaresNoConfigurationConstant(): void
    {
        /**
         * A default is adapter configuration, not part of the contract: Beanstalk puts a TTR
         * on the job it reserves. The base class only knows the methods.
         */
        $this->assertArrayNotHasKey('JOBTTR_DEFAULT', (new ReflectionClass(AbstractAdapter::class))->getConstants());
    }

    public function testJobTtrDefaultIsDeclaredByTheAdapterThatUsesIt(): void
    {
        $this->assertSame(60, Beanstalk::JOBTTR_DEFAULT);
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

    public function testLoggerIsInjectedThroughTheConstructor(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('injected');

        (new TestAdapter($logger))->logError('injected');
    }

    public function testSetLoggerStillAssignsTheDeprecatedSetter(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('setter');

        $adapter = new TestAdapter();
        $adapter->setLogger($logger);
        $adapter->logError('setter');
    }

    public function testLogInfoForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('hello');

        (new TestAdapter($logger))->logInfo('hello');
    }

    public function testLogDebugForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with('trace');

        (new TestAdapter($logger))->logDebug('trace');
    }

    public function testLogErrorForwardsToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('failure');

        (new TestAdapter($logger))->logError('failure');
    }

    public function testLogErrorIsSilentWithoutLogger(): void
    {
        $adapter = new TestAdapter();
        $adapter->logError('no logger attached');
        $this->expectNotToPerformAssertions();
    }
}
