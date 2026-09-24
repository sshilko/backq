<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Process;
use Override;
use const PHP_BINARY;

/**
 * A process message that always reports itself as not yet ready.
 */
class ProcessNotReadyMessage extends Process
{

    public function __construct()
    {
        parent::__construct([PHP_BINARY, '-r', 'exit(0);']);
    }

    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
