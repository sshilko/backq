<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Guzzle;
use Override;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle message that always reports itself as not yet ready.
 */
class GuzzleNotReadyMessage extends Guzzle
{

    public function __construct(RequestInterface $request)
    {
        parent::__construct($request);
    }

    #[Override]
    public function isReady(): bool
    {
        return false;
    }
}
