<?php

namespace BackQ\Tests\Message;

use BackQ\Message\Generic;
use PHPUnit\Framework\TestCase;

class GenericTest extends TestCase
{
    public function testGetData(): void
    {
        $message = new Generic(['job' => 1]);

        $this->assertSame(['job' => 1], $message->getData());
    }

    public function testSerializeRoundTrip(): void
    {
        $message = new Generic(['job' => 1, 'nested' => ['a', 'b']]);

        $payload  = $message->serialize();
        $restored = new Generic(null);
        $restored->unserialize($payload);

        $this->assertSame(['job' => 1, 'nested' => ['a', 'b']], $restored->getData());
    }

    public function testPhpSerializeRoundTrip(): void
    {
        $message  = new Generic('payload');
        $restored = unserialize(serialize($message));

        $this->assertSame('payload', $restored->getData());
    }
}