<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Serialized;
use BackQ\Publisher\AbstractPublisher;
use Override;

/**
 * A serialized message wrapper that always reports itself as already expired.
 */
class ExpiredSerializedMessage extends Serialized
{

    public function __construct(AbstractPublisher $publisher)
    {
        parent::__construct(new NoopMessage(), $publisher);
    }

    #[Override]
    public function isExpired(): bool
    {
        return true;
    }
}
