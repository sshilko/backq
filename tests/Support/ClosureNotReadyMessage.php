<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Closure;
use Opis\Closure\SerializableClosure;
use Override;

/**
 * A closure message that always reports itself as not yet ready.
 */
class ClosureNotReadyMessage extends Closure
{

    public function __construct()
    {
        parent::__construct(new SerializableClosure(static function (): void {
        }));
    }

    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
