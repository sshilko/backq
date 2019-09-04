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
 * Queues a process execution via Redis
 * Publishes a job into default queue="process"
 */

include_once '../../../vendor/autoload.php';

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_READYWAIT = \BackQ\Adapter\Redis::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $output = new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG);
        $logger = new \Symfony\Component\Console\Logger\ConsoleLogger($output);

        $adapter = new \BackQ\Adapter\Redis('127.0.0.1', 6379);
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyProcessPublisher::getInstance();
if ($publisher->start() //&& $publisher->hasWorkers()
   ) {
    $message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
    $result  = $publisher->publish($message, [MyProcessPublisher::PARAM_READYWAIT => random_int(0, 2)]);
    if ($result > 0) {
        /**
         * Success
         */
        echo 'Published process message via redis adapter as ID=' . $result . "\n";
    }
}
