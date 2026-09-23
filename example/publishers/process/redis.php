<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Redis;
use BackQ\Publisher\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 *
 * Queues a process execution via Redis
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MyRedisProcessPublisher extends Process
{
    public const PARAM_READYWAIT = Redis::PARAM_READYWAIT;

    protected function setupAdapter(): AbstractAdapter
    {
        $output = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger = new ConsoleLogger($output);

        $adapter = new Redis('127.0.0.1', 6379);
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyRedisProcessPublisher::getInstance();
if ($publisher->start() //&& $publisher->hasWorkers()
   ) {
    $message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
    $result  = $publisher->publish($message, [MyRedisProcessPublisher::PARAM_READYWAIT => random_int(0, 2)]);
    if ($result) {
        /**
         * Success
         */
        echo 'Published process message via redis adapter as ID=' . $result . "\n";
    }
}
