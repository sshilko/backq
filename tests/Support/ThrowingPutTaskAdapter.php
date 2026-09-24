<?php

namespace BackQ\Tests\Support;

use Override;
use RuntimeException;

/**
 * TestAdapter whose putTask always throws, used to exercise worker error paths.
 */
class ThrowingPutTaskAdapter extends TestAdapter
{

    #[Override]
    public function putTask($body, $params = []): string|bool
    {
        $this->calls[] = ['putTask', $body, $params];

        throw new RuntimeException('putTask exploded');
    }
}
