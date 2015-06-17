<?php
/**
 * BackQ
 *
 * Copyright (c) 2014, Sergey Shilko (contact@sshilko.com)
 *
 * @author Sergey Shilko
 * @see https://github.com/sshilko/backq
 *
 **/
namespace BackQ\Message;

class Generic extends AbstractMessage implements \Serializable
{
    private $data;

    public function __construct($data) {
        $this->data = $data;
    }

    public function serialize() {
        return serialize($this->data);
    }

    public function unserialize($data) {
        $this->data = unserialize($data);
    }

    public function getData() {
        return $this->data;
    }
}
