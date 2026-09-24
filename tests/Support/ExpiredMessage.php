<?php

namespace BackQ\Tests\Support;

use BackQ\Message\AbstractMessage;
use Override;

/**
 * A message that always reports itself as already expired.
 */
class ExpiredMessage extends AbstractMessage
{

    #[Override]
    public function isExpired(): bool
    {
        return true;
    }
}
