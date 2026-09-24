<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use Override;

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

    public bool $hasWorkersResult = false;

    public $pickTaskResult    = false;

    public $putTaskResult     = false;

    public $afterWorkSuccessResult = true;

    public $afterWorkFailedResult  = true;

    public array $calls = [];

    #[Override]
    public function connect(): bool
    {
        $this->calls[] = 'connect';

        return $this->connectResult;
    }

    #[Override]
    public function disconnect(): bool
    {
        $this->calls[] = 'disconnect';

        return $this->disconnectResult;
    }

    #[Override]
    public function bindRead(string $queue): bool
    {
        $this->calls[] = ['bindRead', $queue];

        return $this->bindReadResult;
    }

    #[Override]
    public function bindWrite(string $queue): bool
    {
        $this->calls[] = ['bindWrite', $queue];

        return $this->bindWriteResult;
    }

    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        $this->calls[] = ['pickTask', $timeout];

        return $this->pickTaskResult;
    }

    #[Override]
    public function putTask(string $body, array $params = []): string|bool
    {
        $this->calls[] = ['putTask', $body, $params];

        return $this->putTaskResult;
    }

    #[Override]
    public function afterWorkSuccess(int|string|null $workId): bool
    {
        $this->calls[] = ['afterWorkSuccess', $workId];

        return $this->afterWorkSuccessResult;
    }

    #[Override]
    public function afterWorkFailed(int|string|null $workId): bool
    {
        $this->calls[] = ['afterWorkFailed', $workId];

        return $this->afterWorkFailedResult;
    }

    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        $this->calls[] = ['ping', $reconnect];

        return $this->pingResult;
    }

    #[Override]
    public function hasWorkers(string $queue): bool
    {
        $this->calls[] = ['hasWorkers', $queue];

        return $this->hasWorkersResult;
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        $this->calls[] = ['setWorkTimeout', $seconds];
    }
}
