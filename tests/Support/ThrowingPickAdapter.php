<?php

namespace BackQ\Tests\Support;

use Override;
use RuntimeException;

/**
 * TestAdapter whose pickTask throws after a configurable number of picks.
 */
class ThrowingPickAdapter extends TestAdapter
{

    public int $throwOnPick = PHP_INT_MAX;

    public int $picks = 0;

    #[Override]
    public function pickTask($timeout = null): bool|array
    {
        $this->calls[] = ['pickTask', $timeout];
        ++$this->picks;
        if ($this->picks >= $this->throwOnPick) {
            throw new RuntimeException('pick task exploded');
        }

        return $this->pickTaskResult;
    }
}
