<?php

namespace BackQ\Tests\Support;

use BackQ\Message\GuzzleForwarder;
use Override;

/**
 * A forwarder message that always reports itself as not yet ready.
 */
class NotReadyGuzzleForwarderMessage extends GuzzleForwarder
{
    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
