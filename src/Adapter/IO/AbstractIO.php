<?php

namespace BackQ\Adapter\IO;

abstract class AbstractIO
{
    abstract public function read($n);

    abstract public function write($data);

    abstract public function close();

    abstract public function selectRead($sec, $usec);

    abstract public function selectWrite($sec, $usec);
}