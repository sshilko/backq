<?php

namespace BackQ\Tests\Message;

use BackQ\Message\Generic;
use BackQ\Message\Serialized;
use BackQ\Tests\Support\NoopMessage;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Tests\Support\TestPublisher;
use PHPUnit\Framework\TestCase;

class SerializedTest extends TestCase
{
    public function testGetters(): void
    {
        $inner     = new NoopMessage();
        $publisher = new TestPublisher(new TestAdapter());
        $options   = ['jobttr' => 5];
        $message   = new Serialized($inner, $publisher, $options);

        $this->assertSame($inner, $message->getMessage());
        $this->assertSame($publisher, $message->getPublisher());
        $this->assertSame($options, $message->getPublishOptions());
    }

    public function testDefaults(): void
    {
        $inner   = new Generic('x');
        $message = new Serialized($inner, new TestPublisher(new TestAdapter()));

        $this->assertSame([], $message->getPublishOptions());
        $this->assertSame($inner, $message->getMessage());
        $this->assertInstanceOf(TestPublisher::class, $message->getPublisher());
    }
}
