<?php

declare(strict_types=1);

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

final class RetentionMessage extends \BackQ\Message\Generic
{
    public function __construct(int $userId, protected int $timestamp)
    {
        parent::__construct([$userId, $timestamp]);
    }

    public function getUserId(): int
    {
        return $this->getData()[0];
    }

    public function getTimestamp(): int
    {
        return $this->getData()[1];
    }
}
