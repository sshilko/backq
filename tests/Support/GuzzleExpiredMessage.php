<?php

namespace BackQ\Tests\Support;

use BackQ\Message\Guzzle;
use Override;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle message that always reports itself as already expired.
 */
class GuzzleExpiredMessage extends Guzzle
{

    public function __construct(RequestInterface $request)
    {
        parent::__construct($request);
    }

    #[Override]
    public function isExpired(): bool
    {
        return true;
    }
}
