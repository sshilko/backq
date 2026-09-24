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
use BackQ\Message\Process;
use BackQ\Publisher\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 * Delays `Process` message execution via Serialized worker using the Redis adapter
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/myredisprocesspublisher.php';

final class MyRedisSerializedPublisher extends Serialized
{
    public const PARAM_READYWAIT = Redis::PARAM_READYWAIT;

    protected $queueName = 'serialized';

    protected function setupAdapter(): AbstractAdapter
    {
        $output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new ConsoleLogger($output);

        $adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
        $adapter->setLogger($logger);

        return $adapter;
    }
}

/**
 * We will serialize and delay `process` message
 */
$processPublisher      = MyRedisProcessPublisher::getInstance();
$processMessage        = new Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = [MyRedisProcessPublisher::PARAM_READYWAIT => 1];

/**
 * Delay via serialized message/worker
 */
$publisher      = MyRedisSerializedPublisher::getInstance();
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = [MyRedisSerializedPublisher::PARAM_READYWAIT => 1];

$response = null;
if ($publisher->start()) {
    $response = $publisher->publish($message, $publishOptions);
    if ($response) {
        echo 'Published process message via serialized message for long delay as ID=' . $response . "\n";
    } else {
        echo 'Failed to publish process message via serialized message' . "\n";
        exit(1);
    }
} else {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT reachable?' . "\n";
    exit(1);
}