<?php

namespace BackQ\Tests\Support;

use Override;

/**
 * TestAdapter that sleeps before every pick so idle-timeout logic can elapse.
 */
class SleepingPickAdapter extends TestAdapter
{

    public int $sleepMicros = 600000;

    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        $this->calls[] = ['pickTask', $timeout];
        usleep($this->sleepMicros);

        return $this->pickTaskResult;
    }
}
