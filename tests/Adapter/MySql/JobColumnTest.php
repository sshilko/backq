<?php

namespace BackQ\Tests\Adapter\MySql;

use BackQ\Adapter\MySql\JobColumn;
use PHPUnit\Framework\TestCase;
use function array_column;
use function array_unique;
use function count;

class JobColumnTest extends TestCase
{
    public function testCasesMatchTheColumnsOfTheTableSchema(): void
    {
        /**
         * The adapter owns the state column and the column holding the time of
         * the last state change, the id and the payload columns come from the config
         */
        $this->assertSame(['sync', 'time_sync'], array_column(JobColumn::cases(), 'value'));
    }

    public function testFromResolvesEveryValueBackToItsCase(): void
    {
        foreach (JobColumn::cases() as $case) {
            $this->assertSame($case, JobColumn::from($case->value));
        }
    }

    public function testValuesAreUnique(): void
    {
        $values = array_column(JobColumn::cases(), 'value');

        $this->assertCount(count($values), array_unique($values));
    }

    public function testTryFromRejectsAColumnTheAdapterDoesNotOwn(): void
    {
        $this->assertNull(JobColumn::tryFrom('id'));
        $this->assertNull(JobColumn::tryFrom('payload'));
    }
}
