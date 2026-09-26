<?php

namespace BackQ\Tests\Adapter\MySql;

use BackQ\Adapter\MySql\JobState;
use PHPUnit\Framework\TestCase;
use function array_column;
use function array_unique;
use function count;

class JobStateTest extends TestCase
{
    public function testCasesMatchTheSyncColumnOfTheTableSchema(): void
    {
        /**
         * The `sync` column is declared as enum('WAIT', 'LOCK', 'DONE', 'HOLD'),
         * a value outside of that list is rejected by the server
         */
        $this->assertSame(['WAIT', 'LOCK', 'DONE', 'HOLD'], array_column(JobState::cases(), 'value'));
    }

    public function testFromResolvesEveryValueBackToItsCase(): void
    {
        foreach (JobState::cases() as $case) {
            $this->assertSame($case, JobState::from($case->value));
        }
    }

    public function testValuesAreUnique(): void
    {
        $values = array_column(JobState::cases(), 'value');

        $this->assertCount(count($values), array_unique($values));
    }

    public function testTryFromRejectsAValueTheColumnCannotHold(): void
    {
        $this->assertNull(JobState::tryFrom('DELETED'));
        $this->assertNull(JobState::tryFrom('wait'));
        $this->assertNull(JobState::tryFrom(''));
    }

    public function testPickedJobsAreWaitedAndTheAcksAreDoneOrHold(): void
    {
        /**
         * A picked job is locked while it is worked on, an ack moves it to one
         * of the two final states
         */
        $this->assertSame(JobState::Wait, JobState::from('WAIT'));
        $this->assertSame(JobState::Lock, JobState::from('LOCK'));
        $this->assertSame(JobState::Done, JobState::from('DONE'));
        $this->assertSame(JobState::Hold, JobState::from('HOLD'));
    }
}
