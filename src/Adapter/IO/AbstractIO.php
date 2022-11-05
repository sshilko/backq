<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\IO;

abstract class AbstractIO
{
    abstract public function read($n): void;

    abstract public function write($data): void;

    abstract public function close(): void;

    abstract public function selectRead($sec, $usec): void;

    abstract public function selectWrite($sec, $usec): void;

    /**
     * Advanced functions -->
     */
    abstract public function stream_get_contents(int $length): void;

    abstract public function stream_get_line(int $length, string $delimiter): void;

    abstract public function stream_set_timeout($read_write_timeout): void;
    /**
     * Advanced functions <--
     */
}
