<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Nsq;
use BackQ\Message\Process;
use BackQ\Publisher\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Publisher
 * Delays `Process` message execution via Serialized worker using the NSQ adapter
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/mynsqprocesspublisher.php';

final class MyNsqSerializedPublisher extends Serialized
{
    protected string $queueName = 'serialized';
}

$logger   = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
$nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

$adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
$adapter->setLogger($logger);

/**
 * We will serialize and delay `process` message
 *
 * The options of a Serialized message are the named arguments the worker replays
 * on publish(), so they are keyed by parameter name, not by a constant
 */
$processPublisher      = new MyNsqProcessPublisher(MyNsqProcessPublisher::createAdapter());
$processMessage        = new Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = ['readyWait' => 1];

/**
 * Delay via serialized message/worker
 * NSQ delay (DPUB) is limited by nsqd `--max-req-timeout`, default is 60 minutes
 */
$publisher      = new MyNsqSerializedPublisher($adapter);
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = ['readyWait' => 1];

if (!$publisher->start()) {
    echo 'Failed to start publisher, is nsqd at BACKQ_NSQD_HOST:BACKQ_NSQD_PORT reachable?' . "\n";
    exit(1);
}

$response = $publisher->publish($message, ...$publishOptions);
if ($response instanceof Throwable) {
    echo 'Failed to publish process message via serialized message: ' . $response->getMessage() . "\n";
    exit(1);
}
/**
 * NSQ acknowledges a publish without returning a job id, so a null result is the success
 */
echo 'Published process message via serialized message for long delay' . "\n";