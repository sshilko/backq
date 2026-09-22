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

    protected mixed $data;

    public function __construct(mixed $data)
    {
        $this->data = $data;
    }

    public function __serialize(): array
    {
        return ['data' => $this->data];
    }

    public function __unserialize(array $data): void
    {
        $this->data = $data['data'] ?? null;
    }

    /**
     * @deprecated use native __serialize()/__unserialize() instead
     */
    public function serialize(): string
    {
        return serialize($this->data);
    }

    /**
     * @deprecated use native __serialize()/__unserialize() instead
     */
    public function unserialize(string $data): void
    {
        $this->data = unserialize($data);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
