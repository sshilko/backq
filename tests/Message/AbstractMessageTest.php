<?php

namespace BackQ\Tests\Message;

use BackQ\Message\AbstractMessage;
use PHPUnit\Framework\TestCase;

class AbstractMessageTest extends TestCase
{
    public function testDefaults(): void
    {
        $message = new class extends AbstractMessage {
        };

        $this->assertTrue($message->isReady());
        $this->assertFalse($message->isExpired());
    }
}