<?php

namespace BackQ\Tests\Support;

use Override;
use function posix_getpid;
use function posix_kill;
use const SIGTERM;

/**
 * TestWorker that delivers a signal to its own process after the first pick.
 */
class SignaledWorker extends TestWorker
{

    public int $signal = SIGTERM;

    #[Override]
    public function run(): void
    {
        $connected = $this->start();
        if ($connected) {
            $work = $this->work();
            foreach ($work as $id => $payload) {
                $this->yields[] = [$id, $payload];
                $response       = $this->responses[$this->index] ?? true;
                $this->index++;
                posix_kill(posix_getpid(), $this->signal);
                usleep(50000);
                $work->send($response);
            }
        }
        $this->finish();
    }
}
