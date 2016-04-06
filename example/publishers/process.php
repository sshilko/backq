<?php
/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */


$command = 'echo $( date +%s ) >> /tmp/test';

$publisher = \BackQ\Publisher\Process::getInstance(new \BackQ\Adapter\Beanstalk);
if ($publisher->start() && $publisher->hasWorkers()) {
    $message = new \BackQ\Message\Process($command);
    $result  = $publisher->publish($message, array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR    => 5,
                                                   \BackQ\Adapter\Beanstalk::PARAM_READYWAIT => 1));
    if ($result > 0) {
        /**
         * Success
         */
    }
}
