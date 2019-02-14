<?php
/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */

include_once '../../vendor/autoload.php';

$command = 'echo $( date +%s ) >> /tmp/test';

$adapter = new \BackQ\Adapter\Redis;
$publisher = \BackQ\Publisher\Process::getInstance($adapter);
if ($publisher->start() && $publisher->hasWorkers()) {
    $message = new \BackQ\Message\Process($command);
    $result  = $publisher->publish($message, [\BackQ\Adapter\Beanstalk::PARAM_READYWAIT => 0,
                                              \BackQ\Adapter\Beanstalk::PARAM_JOBTTR => 5]);
    if ($result > 0) {
        /**
         * Success
         */
        echo 'Pushed JOB-id ' . $result;
    }
}

$x = $adapter->pickTask();
print_r($x);

$adapter->disconnect();
