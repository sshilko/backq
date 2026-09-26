<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use Override;
use Stringable;
use Throwable;
use function array_filter;

/**
 * Configurable AbstractAdapter double used to drive workers/publishers
 * without touching any external queue service.
 *
 * It widens putTask() with the parameters of every shipped adapter, so a test
 * can assert what AbstractPublisher::publish() forwarded without caring which
 * adapter is behind it.
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

    /**
     * What putTask() hands back: a job id, null for an adapter that stores the job
     * without reporting an id, or the Throwable that stands in for a failure
     */
    public null|string|Throwable $putTaskResult = null;

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

    /**
     * Widened with the parameters of every shipped adapter, so a test can assert what
     * publish() forwarded. Only the parameters the caller actually passed are recorded.
     */
    #[Override]
    public function putTask(
        string|Stringable $body,
        int $readyWait = 0,
        ?int $jobTtr = null,
        ?int $priority = null,
        int|string|null $messageId = null,
        int|string|null $jobId = null,
        bool $putAsDone = false,
        bool $noSleep = false,
    ): null|string|Throwable {
        $this->calls[] = ['putTask', $body, array_filter(
            [
                'jobId'     => $jobId,
                'jobTtr'    => $jobTtr,
                'messageId' => $messageId,
                'noSleep'   => $noSleep,
                'priority'  => $priority,
                'putAsDone' => $putAsDone,
                'readyWait' => $readyWait,
            ],
            static function (mixed $value): bool {
                return null !== $value && false !== $value;
            }
        )];

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
