<?php

namespace BackQ\Tests\Support;

use Override;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * TestAdapter whose putTask returns a Throwable instead of raising it, used to exercise the
 * transport-failure branch: a put that fails on the wire is reported as a value, not an exception.
 */
class FailingPutTaskAdapter extends TestAdapter
{

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
        $this->calls[] = ['putTask', $body];

        return new RuntimeException('putTask exploded');
    }
}
