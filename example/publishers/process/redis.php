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

        $adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyRedisProcessPublisher::getInstance();
if (!$publisher->start()) {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT (default 127.0.0.1:6379) reachable?' . "\n";
    exit(1);
}

$message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
try {
    $result = $publisher->publish($message, [MyRedisProcessPublisher::PARAM_READYWAIT => random_int(0, 2)]);
} catch (Throwable $e) {
    echo 'Failed to publish process message via redis adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($result) {
    /**
     * Success
     */
    echo 'Published process message via redis adapter as ID=' . $result . "\n";
} else {
    echo 'Failed to publish process message via redis adapter' . "\n";
    exit(1);
}
