<?php

namespace BackQ\Tests\Adapter\MySql;

use BackQ\Adapter\MySql\JobConfig;
use Error;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
