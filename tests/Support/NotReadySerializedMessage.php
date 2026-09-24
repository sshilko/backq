<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Serialized;
use BackQ\Publisher\AbstractPublisher;
use Override;

/**
 * A serialized message wrapper that always reports itself as not yet ready.
 */
class NotReadySerializedMessage extends Serialized
{

    public function __construct(AbstractPublisher $publisher)
    {
        parent::__construct(new NoopMessage(), $publisher);
    }

    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
