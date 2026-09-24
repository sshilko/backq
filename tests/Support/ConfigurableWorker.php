<?php

namespace BackQ\Tests\Support;

use Override;

/**
 * TestWorker whose idle/restart threshold hooks can be switched off.
 */
class ConfigurableWorker extends TestWorker
{

    public bool $idleTimeoutResult      = true;

    public bool $restartThresholdResult = true;

    #[Override]
    protected function onIdleTimeout(): bool
    {
        return $this->idleTimeoutResult;
    }

    #[Override]
    protected function onRestartThreshold(): bool
    {
        return $this->restartThresholdResult;
    }
}
