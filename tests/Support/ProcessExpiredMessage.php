<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Process;
use Override;
use const PHP_BINARY;

/**
 * A process message that always reports itself as already expired.
 */
class ProcessExpiredMessage extends Process
{

    public function __construct()
    {
        parent::__construct([PHP_BINARY, '-r', 'exit(0);']);
    }

    #[Override]
    public function isExpired(): bool
    {
        return true;
    }
}
