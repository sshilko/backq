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

use Opis\Closure\SerializableClosure;

class Closure extends AbstractMessage
{

    protected SerializableClosure $function;

    public function __construct(SerializableClosure $function)
    {
        $this->function = $function;
    }

    /**
     * Executes the closure for this message
     *
     * @return mixed
     */
    public function execute()
    {
        $closure = $this->function->getClosure();

        return $closure();
    }
}
