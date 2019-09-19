<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message;

abstract class AbstractMessage implements ConsumeInterface
{
    /**
     * @return bool
     */
    public function isReady(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        return false;
    }
}
