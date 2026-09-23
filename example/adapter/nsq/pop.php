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
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Example subscriber using
 * Adapter for NSQ
 * @see http://nsq.io
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
$nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

$nsqsub = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
$nsqsub->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)));
$nsqsub->logInfo('Starting');
$nsqsub->setWorkTimeout(5);
if (!$nsqsub->connect()) {
    echo 'Failed to connect to nsqd at ' . $nsqdHost . ':' . $nsqdPort . "\n";
    exit(1);
}
$nsqsub->logInfo('Connected');
if ($nsqsub->bindRead($queue)) {
        $nsqsub->logInfo('Subscribed');
        $i = 100;
        while ($i > 0) {
            $nsqsub->logInfo('Picking task');
            $job = $nsqsub->pickTask();
            if ($job && $job[0]) {
                $nsqsub->logInfo('Got task: ' . json_encode($job));
                if (1 === rand(1, 2)) {
                    $nsqsub->logInfo('Reporting success');
                    $nsqsub->afterWorkSuccess($job[0]);
                } else {
                    $nsqsub->logInfo('Reporting failure');
                    $nsqsub->afterWorkFailed($job[0]);
                }
            } else {
                $nsqsub->logInfo('No job received within work timeout');
            }
            $i--;
        }
    } else {
        $nsqsub->logError('Failed to bind to read queue ' . $queue);
        exit(1);
    }
$nsqsub->logInfo('All done');
$nsqsub->disconnect();
$nsqsub->logInfo('Disconnected');
