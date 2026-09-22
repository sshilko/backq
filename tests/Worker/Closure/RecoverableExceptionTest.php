<?php

namespace BackQ\Tests\Worker\Closure;

use BackQ\Worker\Closure\RecoverableException;
use PHPUnit\Framework\TestCase;
use Throwable;

class RecoverableExceptionTest extends TestCase
{
    public function testIsThrowable(): void
    {
        $exception = new RecoverableException('boom', 42);

        $this->assertInstanceOf(Throwable::class, $exception);
        $this->assertSame('boom', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
    }

    public function testCannotBeExtended(): void
    {
        $this->assertTrue((new \ReflectionClass(RecoverableException::class))->isFinal());
    }
}
