<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message;

/**
 * Interface ConsumeInterface
 */
interface ConsumeInterface
{
    /**
     * Whether a message is currently ready for processing
     */
    public function isReady(): bool;

    /**
     * Whether the message is still valid for further processing
     */
    public function isExpired(): bool;
}
