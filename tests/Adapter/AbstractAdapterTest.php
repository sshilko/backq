<?php

namespace BackQ\Tests\Adapter;

use ArgumentCountError;
use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Beanstalk;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TypeError;
use function method_exists;

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

    public function testTheLoggerIsMandatory(): void
    {
        $parameter = (new ReflectionMethod(AbstractAdapter::class, '__construct'))->getParameters()[0];

        $this->assertSame('logger', $parameter->getName());
        $this->assertSame(LoggerInterface::class, (string) $parameter->getType());
        /**
         * No default, so an adapter cannot be built without a logger
         */
        $this->assertFalse($parameter->isDefaultValueAvailable());
    }

    public function testTheLoggerIsRequiredAtCallTime(): void
    {
        /**
         * The base constructor, called directly: the double supplies a NullLogger of its own
         * accord, so invoking the constructor it inherits would hide the requirement.
         */
        $adapter = (new ReflectionClass(TestAdapter::class))->newInstanceWithoutConstructor();

        $this->expectException(ArgumentCountError::class);

        (new ReflectionMethod(AbstractAdapter::class, '__construct'))->invoke($adapter);
    }

    public function testTheNullLoggerIsRejected(): void
    {
        $this->expectException(TypeError::class);

        new TestAdapter(null);
    }

    public function testTheInjectedLoggerIsTheOneTheAdapterHolds(): void
    {
        $logger = new RecordingLogger();

        $this->assertSame(
            $logger,
            (new ReflectionProperty(AbstractAdapter::class, 'logger'))->getValue(new TestAdapter($logger))
        );
    }

    public function testTheLoggerHelpersAreGone(): void
    {
        /**
         * An adapter logs through the injected PSR-3 logger, it does not wrap it
         */
        $adapter = new TestAdapter();

        $this->assertFalse(method_exists($adapter, 'logInfo'));
        $this->assertFalse(method_exists($adapter, 'logDebug'));
        $this->assertFalse(method_exists($adapter, 'logError'));
        $this->assertFalse(method_exists($adapter, 'setLogger'));
    }
}
