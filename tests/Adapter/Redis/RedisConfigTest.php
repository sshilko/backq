<?php

namespace BackQ\Tests\Adapter\Redis;

use BackQ\Adapter\Redis\RedisConfig;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RedisConfigTest extends TestCase
{
    public function testTheDefaultsMatchTheSettingsTheAdapterUsedToTakeOneByOne(): void
    {
        $config = new RedisConfig();

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(6379, $config->port);
        $this->assertFalse($config->persistent);
        $this->assertNull($config->persistentId);
        $this->assertNull($config->prefix);
        $this->assertSame(10, $config->timeout);
        $this->assertSame(10, $config->readTimeout);
        $this->assertSame(0, $config->databaseId);
        $this->assertNull($config->authPassword);
        $this->assertSame('default', $config->queueName);
        $this->assertSame(300, $config->workerTtl);
    }

    public function testEveryValueCanBeOverridden(): void
    {
        $config = new RedisConfig('redis.example', 6380, true, 'backq', 'queue:', 3, 30, 2, 'secret', 'the-queue', 900);

        $this->assertSame('redis.example', $config->host);
        $this->assertSame(6380, $config->port);
        $this->assertTrue($config->persistent);
        $this->assertSame('backq', $config->persistentId);
        $this->assertSame('queue:', $config->prefix);
        $this->assertSame(3, $config->timeout);
        $this->assertSame(30, $config->readTimeout);
        $this->assertSame(2, $config->databaseId);
        $this->assertSame('secret', $config->authPassword);
        $this->assertSame('the-queue', $config->queueName);
        $this->assertSame(900, $config->workerTtl);
    }

    public function testTheValuesAreNamedAfterTheSettingsNotAfterTheIlluminateKeys(): void
    {
        $config = new RedisConfig(readTimeout: 5, databaseId: 1);

        $this->assertSame(5, $config->readTimeout);
        $this->assertSame(1, $config->databaseId);
    }

    #[DataProvider('portProvider')]
    public function testAPortOutsideTheTcpRangeIsRejected(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port');

        new RedisConfig(port: $port);
    }

    public function testTheLowestAndHighestPortAreAccepted(): void
    {
        $this->assertSame(1, (new RedisConfig(port: 1))->port);
        $this->assertSame(65535, (new RedisConfig(port: 65535))->port);
    }

    #[DataProvider('timeoutProvider')]
    public function testATimeoutBelowASecondIsRejected(string $setting, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($setting);

        new RedisConfig(...[$setting => $value]);
    }

    public function testANegativeDatabaseIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('databaseId');

        new RedisConfig(databaseId: -1);
    }

    public function testTheMessageSaysWhatTheSettingAccepts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535, got 0');

        new RedisConfig(port: 0);
    }

    /**
     * A worker lease of 0 makes every worker look absent and a lease of 1 makes the registry
     * flap on every pick cycle. Both are silent, so they are rejected at construction.
     */
    #[DataProvider('workerTtlProvider')]
    public function testAWorkerTtlTooShortToBeUsefulIsRejected(int $workerTtl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workerTtl');

        new RedisConfig(workerTtl: $workerTtl);
    }

    public function testTheShortestWorkerTtlIsAccepted(): void
    {
        $this->assertSame(5, (new RedisConfig(workerTtl: 5))->workerTtl);
    }

    public function testTheConfigIsReadOnly(): void
    {
        $this->assertTrue((new ReflectionClass(RedisConfig::class))->isReadOnly());
    }

    public function testAValueCannotBeChangedAfterConstruction(): void
    {
        $config = new RedisConfig();

        $this->expectException(Error::class);

        $config->host = 'other';
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function portProvider(): array
    {
        return [
            'above the tcp range' => [65536],
            'far above' => [70000],
            'negative' => [-1],
            'zero' => [0],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function timeoutProvider(): array
    {
        return [
            'readTimeout negative' => ['readTimeout', -1],
            'readTimeout zero' => ['readTimeout', 0],
            'timeout negative' => ['timeout', -1],
            'timeout zero' => ['timeout', 0],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function workerTtlProvider(): array
    {
        return [
            'negative' => [-1],
            'one below the minimum' => [4],
            'one too short to survive a pick cycle' => [1],
            'zero' => [0],
        ];
    }
}
