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
namespace BackQ\Publisher;

class Apnsd extends AbstractPublisher
{
    public function getQueueName() {
        return 'apnsd';
    }
}
