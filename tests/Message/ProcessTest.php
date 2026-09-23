<?php

namespace BackQ\Tests\Message;

use BackQ\Message\Process;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase
{
    public function testDefaults(): void
    {
        $message = new Process(['echo', 'hi']);

        $this->assertSame(['echo', 'hi'], $message->getCommandline());
        $this->assertNull($message->getCwd());
        $this->assertNull($message->getEnv());
        $this->assertNull($message->getInput());
        $this->assertSame(60.0, $message->getTimeout());
        $this->assertSame(0, $message->getDeadline());
    }

    public function testGetters(): void
    {
        $env     = ['FOO' => 'bar'];
        $message = new Process('echo hi', '/tmp', $env, 'input', 120.5);

        $this->assertSame('echo hi', $message->getCommandline());
        $this->assertSame('/tmp', $message->getCwd());
        $this->assertSame($env, $message->getEnv());
        $this->assertSame('input', $message->getInput());
        $this->assertSame(120.5, $message->getTimeout());
    }

    public function testSetDeadline(): void
    {
        $message = new Process(['ls']);
        $message->setDeadline(123456789);

        $this->assertSame(123456789, $message->getDeadline());
    }
}
