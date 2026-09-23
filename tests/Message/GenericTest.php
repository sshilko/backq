<?php

namespace BackQ\Tests\Message;

use BackQ\Message\Generic;
use PHPUnit\Framework\TestCase;
use function serialize;
use function sprintf;
use function strlen;
use function unserialize;

class GenericTest extends TestCase
{
    private const array PAYLOAD = ['job' => 1, 'nested' => ['a', 'b']];

    public function testGetData(): void
    {
        $message = new Generic(self::PAYLOAD);

        $this->assertSame(self::PAYLOAD, $message->getData());
    }

    public function testMagicMethodsShape(): void
    {
        $message = new Generic(self::PAYLOAD);

        $this->assertSame(['data' => self::PAYLOAD], $message->__serialize());
    }

    public function testLegacyBridgeShape(): void
    {
        $message = new Generic(self::PAYLOAD);

        $this->assertSame(serialize(self::PAYLOAD), $message->serialize());
    }

    public function testLegacyBridgeRoundTrip(): void
    {
        $message = new Generic(self::PAYLOAD);

        $payload  = $message->serialize();
        $restored = new Generic(null);
        $restored->unserialize($payload);

        $this->assertSame(self::PAYLOAD, $restored->getData());
    }

    public function testNativeRoundTrip(): void
    {
        $message  = new Generic(self::PAYLOAD);
        $capsule  = serialize($message);
        $restored = unserialize($capsule);

        $this->assertSame(self::PAYLOAD, $restored->getData());
        $this->assertInstanceOf(Generic::class, $restored);
        $this->assertStringStartsWith('O:21:"BackQ\Message\Generic"', $capsule);
    }

    public function testLegacyWireCapsuleReadByNewGeneric(): void
    {
        $name = Generic::class;
        $body = serialize(self::PAYLOAD);

        $capsule = sprintf(
            'C:%d:"%s":%d:{%s}',
            strlen($name),
            $name,
            strlen($body),
            $body
        );

        $restored = unserialize($capsule);

        $this->assertInstanceOf(Generic::class, $restored);
        $this->assertSame(self::PAYLOAD, $restored->getData());
    }

    public function testLegacyBodyProducedByNewGenericIsPlainSerializedData(): void
    {
        $body = (new Generic(self::PAYLOAD))->serialize();

        $this->assertSame(self::PAYLOAD, unserialize($body));
    }
}
