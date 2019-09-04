<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 */

include_once '../../vendor/autoload.php';

$command = 'echo $( date +%s ) >> /tmp/test';

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        return new \BackQ\Adapter\Beanstalk;
    }
}

$publisher = MyProcessPublisher::getInstance();
if ($publisher->start() //&& $publisher->hasWorkers()
   ) {
    $message = new \BackQ\Message\Process($command);
    $result  = $publisher->publish($message, [MyProcessPublisher::PARAM_JOBTTR    => 5,
                                              MyProcessPublisher::PARAM_READYWAIT => 1]);
    if ($result > 0) {
        echo "Published `" . $message->getCommandline() . '`` as ID=' . $result . "\n";
        /**
         * Success
         */
    }
}
