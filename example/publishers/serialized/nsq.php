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
use BackQ\Adapter\Nsq;
use BackQ\Message\Process;
use BackQ\Publisher\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 * Delays `Process` message execution via Serialized worker using the NSQ adapter
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/mynsqprocesspublisher.php';

final class MyNsqSerializedPublisher extends Serialized
{
    public const PARAM_READYWAIT = Nsq::PARAM_READYWAIT;

    protected $queueName = 'serialized';

    protected function setupAdapter(): AbstractAdapter
    {
        $output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new ConsoleLogger($output);

        $nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
        $nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

        $adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
        $adapter->setLogger($logger);

        return $adapter;
    }
}

/**
 * We will serialize and delay `process` message
 */
$processPublisher      = MyNsqProcessPublisher::getInstance();
$processMessage        = new Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = [MyNsqProcessPublisher::PARAM_READYWAIT => 1];

/**
 * Delay via serialized message/worker
 * NSQ delay (DPUB) is limited by nsqd `--max-req-timeout`, default is 60 minutes
 */
$publisher      = MyNsqSerializedPublisher::getInstance();
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = [MyNsqSerializedPublisher::PARAM_READYWAIT => 1];

$response = null;
if ($publisher->start()) {
    $response = $publisher->publish($message, $publishOptions);
    if ($response) {
        echo 'Published process message via serialized message for long delay' . "\n";
    } else {
        echo 'Failed to publish process message via serialized message' . "\n";
        exit(1);
    }
} else {
    echo 'Failed to start publisher, is nsqd at BACKQ_NSQD_HOST:BACKQ_NSQD_PORT reachable?' . "\n";
    exit(1);
}