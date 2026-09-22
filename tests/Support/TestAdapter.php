<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;

/**
 * Configurable AbstractAdapter double used to drive workers/publishers
 * without touching any external queue service.
 */
class TestAdapter extends AbstractAdapter
{
    public $connectResult     = true;
    public $disconnectResult  = true;
    public $bindReadResult    = true;
    public $bindWriteResult   = true;
    public $pingResult        = true;
    public $hasWorkersResult  = false;
    public $pickTaskResult    = false;
    public $putTaskResult     = false;
    public $afterWorkSuccessResult = true;
    public $afterWorkFailedResult  = true;

    public array $calls = [];

    public function connect(): bool
    {
        $this->calls[] = 'connect';

        return $this->connectResult;
    }

    public function disconnect(): bool
    {
        $this->calls[] = 'disconnect';

        return $this->disconnectResult;
    }

    public function bindRead($queue): bool
    {
        $this->calls[] = ['bindRead', $queue];

        return $this->bindReadResult;
    }

    public function bindWrite($queue): bool
    {
        $this->calls[] = ['bindWrite', $queue];

        return $this->bindWriteResult;
    }

    public function pickTask($timeout = null): bool|array
    {
        $this->calls[] = ['pickTask', $timeout];

        return $this->pickTaskResult;
    }

    public function putTask($body, $params = []): string|bool
    {
        $this->calls[] = ['putTask', $body, $params];

        return $this->putTaskResult;
    }

    public function afterWorkSuccess($workId): bool
    {
        $this->calls[] = ['afterWorkSuccess', $workId];

        return $this->afterWorkSuccessResult;
    }

    public function afterWorkFailed($workId): bool
    {
        $this->calls[] = ['afterWorkFailed', $workId];

        return $this->afterWorkFailedResult;
    }

    public function ping($reconnect = true): bool
    {
        $this->calls[] = ['ping', $reconnect];

        return $this->pingResult;
    }

    public function hasWorkers($queue): bool|int|null
    {
        $this->calls[] = ['hasWorkers', $queue];

        return $this->hasWorkersResult;
    }

    public function setWorkTimeout(?int $seconds = null): void
    {
        $this->calls[] = ['setWorkTimeout', $seconds];
    }
}