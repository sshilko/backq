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

use Serializable;
use function serialize;
use function unserialize;

class Generic extends AbstractMessage implements Serializable
{

    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function serialize()
    {
        return serialize($this->data);
    }

    public function unserialize($data): void
    {
        $this->data = unserialize($data);
    }

    public function getData()
    {
        return $this->data;
    }
}
