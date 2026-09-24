<?php

namespace BackQ\Tests\Support;

use BackQ\Message\AbstractMessage;
use Override;

/**
 * A message that always reports itself as not yet ready.
 */
class NotReadyMessage extends AbstractMessage
{

    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
