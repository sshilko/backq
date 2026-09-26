<?php

namespace BackQ\Tests\Support;

use Override;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * TestAdapter whose putTask always throws, used to exercise the programmer-error branch:
 * a bad argument is a bug in the caller, so it is raised rather than returned.
 */
class ThrowingPutTaskAdapter extends TestAdapter
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

        throw new RuntimeException('putTask exploded');
    }
}
