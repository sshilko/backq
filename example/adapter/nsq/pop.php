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

/**
 * Example subscriber using
 * Adapter for NSQ
 * @see http://nsq.io
 */
include_once '../../../src/Adapter/AbstractAdapter.php';
include_once '../../../src/Adapter/IO/AbstractIO.php';
include_once '../../../src/Adapter/IO/StreamIO.php';
include_once '../../../src/Adapter/Nsq.php';
include_once '../../../src/Adapter/IO/Exception/IOException.php';
include_once '../../../src/Adapter/IO/Exception/TimeoutException.php';
include_once '../../../src/Adapter/IO/Exception/RuntimeException.php';

$queue = 'hello-world';

$nsqsub = new Nsq('127.0.0.1', 4150, ['persistent' => false]);
$nsqsub->logInfo('Starting');
$nsqsub->setWorkTimeout(5);
if ($nsqsub->connect()) {
    $nsqsub->logInfo('Connected');
    if ($nsqsub->bindRead($queue)) {
        $nsqsub->logInfo('Subscribed');
        $i = 100;
        while ($i > 0) {
            $nsqsub->logInfo('Picking task');
            $job = $nsqsub->pickTask();
            if ($job) {
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
    }
}
$nsqsub->logInfo('All done');
$nsqsub->disconnect();
$nsqsub->logInfo('Disconnected');
