<?php

namespace BackQ\Tests\Adapter\Redis;

use BackQ\Adapter\Redis\Queue;
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class QueueTest extends TestCase
{
    public function testSetBlockForWritesProtectedProperty(): void
    {
        $queue = new Queue($this->createMock(Factory::class));

        $queue->setBlockFor(5);

        $this->assertSame(5, (new ReflectionProperty(Queue::class, 'blockFor'))->getValue($queue));
    }

    public function testSetBlockForAcceptsNull(): void
    {
        $queue = new Queue($this->createMock(Factory::class));

        $queue->setBlockFor(null);

        $this->assertNull((new ReflectionProperty(Queue::class, 'blockFor'))->getValue($queue));
    }
}
