<?php
/**
* BackQ
*
* Copyright (c) 2014, Sergey Shilko (contact@sshilko.com)
*
* @author Sergey Shilko
* @see https://github.com/sshilko/backq
* @see http://symfony.com/doc/current/components/process.html
*
**/
namespace BackQ\Publisher;

class Process extends AbstractPublisher
{
    private $queueName = 'process';

    /**
     * Queue this publisher will publish to
     *
     * @return string
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    /**
     * Set queue this publisher will publish to
     *
     * @param $string
     */
    public function setQueueName($string)
    {
        $this->queueName = (string) $string;
    }    
}
