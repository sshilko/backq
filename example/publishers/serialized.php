<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Message\Process;
use BackQ\Publisher\Serialized;
use Throwable;

/**
 * Publisher
 * Delays `Process` message execution via Serialized worker
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib/myprocesspublisher.php';

final class MySerializedPublisher extends Serialized
{
    protected string $queueName = 'serialized';
}

/**
 * We will serialize and delay `process` message
 *
 * The options of a Serialized message are the named arguments the worker replays
 * on publish(), so they are keyed by parameter name, not by a constant
 */
$processPublisher      = new MyProcessPublisher(MyProcessPublisher::createAdapter());
$processMessage        = new Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = ['jobTtr' => 5, 'readyWait' => 1];


/**
 * Delay via serialized message/worker
 */
$publisher      = new MySerializedPublisher(MyProcessPublisher::createAdapter());
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = ['jobTtr' => 10, 'readyWait' => 1];

if (!$publisher->start()) {
    echo 'Failed to start publisher, is beanstalkd running on 127.0.0.1:11300?' . "\n";
    exit(1);
}

$response = $publisher->publish($message, ...$publishOptions);
if ($response instanceof Throwable) {
    echo 'Failed to publish process message via serialized message: ' . $response->getMessage() . "\n";
    exit(1);
}
echo 'Published process message via serialized message for long delay as ID=' . $response . "\n";
