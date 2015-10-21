<?php

namespace BackQ\Adapter\IO;

abstract class AbstractIO
{
    abstract public function read($n, $unsafe = false);

    abstract public function write($data);

    abstract public function close();

    abstract public function select($sec, $usec);
}