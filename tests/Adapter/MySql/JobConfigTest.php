<?php

namespace BackQ\Tests\Adapter\MySql;

use BackQ\Adapter\MySql\JobConfig;
use Error;
use InvalidArgumentException;
use mysqli;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class JobConfigTest extends TestCase
{
    public function testDefaultColumnsAndTableMatchTheSchemaDocumentedInTheAdapter(): void
    {
        $config = new JobConfig();

        $this->assertSame('id', $config->idColumn);
        $this->assertSame('payload', $config->dataColumn);
        $this->assertSame('backq_jobs', $config->table);
    }

    public function testDefaultSleepsThrottleTheStatements(): void
    {
        $config = new JobConfig();

        $this->assertSame(5000000, $config->pickMissSleep);
        $this->assertSame(1000000, $config->pickSuccessSleep);
        $this->assertSame(50000, $config->putTaskSleep);
    }

    public function testEveryValueCanBeOverridden(): void
    {
        $config = new JobConfig('uid', 'body', 'queue', 1, 2, 3);

        $this->assertSame('uid', $config->idColumn);
        $this->assertSame('body', $config->dataColumn);
        $this->assertSame('queue', $config->table);
        $this->assertSame(1, $config->pickMissSleep);
        $this->assertSame(2, $config->pickSuccessSleep);
        $this->assertSame(3, $config->putTaskSleep);
    }

    public function testTheDefaultsSurviveAnEmptyConfig(): void
    {
        $config = new JobConfig('id', 'payload', 'backq_jobs', 0, 0, 0);

        $this->assertSame('id', $config->idColumn);
        $this->assertSame('payload', $config->dataColumn);
        $this->assertSame('backq_jobs', $config->table);
        $this->assertSame(0, $config->pickMissSleep);
        $this->assertSame(0, $config->pickSuccessSleep);
        $this->assertSame(0, $config->putTaskSleep);
    }

    public function testThereIsNoConnectionProviderByDefault(): void
    {
        $this->assertNull((new JobConfig())->connectionProvider);
    }

    public function testTheConnectionProviderIsTheCallersToBuild(): void
    {
        $provider = static function (): mysqli {
            throw new RuntimeException('not called by this test');
        };

        $config = new JobConfig(connectionProvider: $provider);

        $this->assertSame($provider, $config->connectionProvider);
    }

    /**
     * The two registry fields were appended after the connection provider, and the two tests
     * above construct JobConfig with named arguments while testEveryValueCanBeOverridden()
     * and testTheDefaultsSurviveAnEmptyConfig() pass seven positionals. A field inserted in
     * the middle would move the provider out from under the seventh positional and the suite
     * would say so here rather than in a test about something else.
     */
    public function testTheRegistryFieldsComeAfterTheConnectionProvider(): void
    {
        $provider = static function (): mysqli {
            throw new RuntimeException('not called by this test');
        };

        $config = new JobConfig('id', 'payload', 'backq_jobs', 0, 0, 0, $provider);

        $this->assertSame($provider, $config->connectionProvider, 'the provider is still the seventh positional');
        $this->assertSame('backq_workers', $config->workerTable, 'and the registry fields come after it');
        $this->assertSame(300, $config->workerTtl);
    }

    public function testTheWorkerRegistryHasItsOwnTableAndLeaseLength(): void
    {
        $config = new JobConfig();

        $this->assertSame('backq_workers', $config->workerTable);
        $this->assertSame(300, $config->workerTtl);
    }

    public function testTheRegistrySettingsCanBeOverridden(): void
    {
        $config = new JobConfig(workerTable: 'staff', workerTtl: 30);

        $this->assertSame('staff', $config->workerTable);
        $this->assertSame(30, $config->workerTtl);
    }

    /**
     * A zero lease expires the row the instant the server writes it, so hasWorkers() answers
     * false for the lifetime of every process and nothing anywhere reports an error
     */
    public function testALeaseShorterThanTheMinimumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workerTtl must be at least 5 seconds, got 4');

        new JobConfig(workerTtl: 4);
    }

    public function testTheMinimumLeaseLengthIsAccepted(): void
    {
        $this->assertSame(5, (new JobConfig(workerTtl: JobConfig::WORKER_TTL_MIN))->workerTtl);
    }

    public function testTheConfigIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(JobConfig::class))->isFinal());
    }

    public function testTheConfigIsReadOnly(): void
    {
        $this->assertTrue((new ReflectionClass(JobConfig::class))->isReadOnly());
    }

    public function testAValueCannotBeChangedAfterConstruction(): void
    {
        $config = new JobConfig();

        $this->expectException(Error::class);

        $config->table = 'other';
    }
}
