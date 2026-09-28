<?php

namespace BackQ\Tests\Adapter\Beanstalk;

use BackQ\Adapter\Beanstalk\Connection;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ConnectionTest extends TestCase
{
    public function testTheDefaultsMatchTheSettingsTheAdapterUsedToTakeOneByOne(): void
    {
        $connection = new Connection();

        $this->assertSame('127.0.0.1', $connection->host);
        $this->assertSame(11300, $connection->port);
        $this->assertSame(1, $connection->timeout);
        $this->assertFalse($connection->persistent);
        $this->assertNull($connection->context);
    }

    public function testEveryValueCanBeOverridden(): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true]]);
        $connection = new Connection('beanstalkd.example', 11301, 5, true, $context);

        $this->assertSame('beanstalkd.example', $connection->host);
        $this->assertSame(11301, $connection->port);
        $this->assertSame(5, $connection->timeout);
        $this->assertTrue($connection->persistent);
        $this->assertSame($context, $connection->context);
    }

    #[DataProvider('portProvider')]
    public function testAPortOutsideTheTcpRangeIsRejected(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port');

        new Connection(port: $port);
    }

    public function testTheLowestAndHighestPortAreAccepted(): void
    {
        $this->assertSame(1, (new Connection(port: 1))->port);
        $this->assertSame(65535, (new Connection(port: 65535))->port);
    }

    public function testAsPersistentKeepsEveryOtherSettingAndLeavesTheOriginalAlone(): void
    {
        $context    = stream_context_create(['ssl' => ['verify_peer' => true]]);
        $connection = new Connection('beanstalkd.example', 11301, 5, false, $context);

        $persistent = $connection->asPersistent();

        $this->assertTrue($persistent->persistent);
        $this->assertSame('beanstalkd.example', $persistent->host);
        $this->assertSame(11301, $persistent->port);
        $this->assertSame(5, $persistent->timeout);
        $this->assertSame($context, $persistent->context);
        $this->assertFalse($connection->persistent);
    }

    public function testAsPersistentOnAPersistentConnectionChangesNothing(): void
    {
        $connection = new Connection(persistent: true);

        $this->assertTrue($connection->asPersistent()->persistent);
    }

    public function testTheConfigIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(Connection::class))->isFinal());
    }

    public function testTheConfigIsReadOnly(): void
    {
        $this->assertTrue((new ReflectionClass(Connection::class))->isReadOnly());
    }

    public function testAValueCannotBeChangedAfterConstruction(): void
    {
        $connection = new Connection();

        $this->expectException(Error::class);

        $connection->host = 'other';
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function portProvider(): array
    {
        return [
            'above the tcp range' => [65536],
            'negative' => [-1],
            'zero' => [0],
        ];
    }
}
