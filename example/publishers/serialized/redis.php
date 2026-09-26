<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Redis;
use BackQ\Message\Process;
use BackQ\Publisher\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Publisher
 * Delays `Process` message execution via Serialized worker using the Redis adapter
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/myredisprocesspublisher.php';

final class MyRedisSerializedPublisher extends Serialized
{
    protected string $queueName = 'serialized';
}

$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
$adapter->setLogger($logger);

/**
 * We will serialize and delay `process` message
 *
 * The options of a Serialized message are the named arguments the worker replays
 * on publish(), so they are keyed by parameter name, not by a constant
 */
$processPublisher      = new MyRedisProcessPublisher(MyRedisProcessPublisher::createAdapter());
$processMessage        = new Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = ['readyWait' => 1];

/**
 * Delay via serialized message/worker
 */
$publisher      = new MyRedisSerializedPublisher($adapter);
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = ['readyWait' => 1];

if (!$publisher->start()) {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT reachable?' . "\n";
    exit(1);
}

$response = $publisher->publish($message, ...$publishOptions);
if ($response instanceof Throwable) {
    echo 'Failed to publish process message via serialized message: ' . $response->getMessage() . "\n";
    exit(1);
}
echo 'Published process message via serialized message for long delay as ID=' . $response . "\n";