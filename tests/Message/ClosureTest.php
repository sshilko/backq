<?php

namespace BackQ\Tests\Message;

use ArrayObject;
use BackQ\Message\Closure;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;

class ClosureTest extends TestCase
{
    public function testExecute(): void
    {
        $box     = new ArrayObject(['count' => 0]);
        $closure = new SerializableClosure(static function () use ($box): int {
            $box['count']++;

            return $box['count'];
        });
        $message = new Closure($closure);

        $this->assertSame(1, $message->execute());
        $this->assertSame(2, $message->execute());
        $this->assertSame(2, $box['count']);
    }

    public function testSerializationRoundTrip(): void
    {
        $box     = new ArrayObject(['value' => 'hello']);
        $closure = new SerializableClosure(static function () use ($box): string {
            return (string) $box['value'];
        });
        $message = new Closure($closure);

        $restored = unserialize(serialize($message));

        $this->assertInstanceOf(Closure::class, $restored);
        $this->assertSame('hello', $restored->execute());
    }
}