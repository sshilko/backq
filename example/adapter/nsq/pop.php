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
 * Example subscriber using
 * Adapter for NSQ
 * @see http://nsq.io
 */
include_once('../../../src/Adapter/AbstractAdapter.php');
include_once('../../../src/Adapter/IO/AbstractIO.php');
include_once('../../../src/Adapter/IO/StreamIO.php');
include_once('../../../src/Adapter/Nsq.php');
include_once('../../../src/Adapter/IO/Exception/IOException.php');
include_once('../../../src/Adapter/IO/Exception/TimeoutException.php');
include_once('../../../src/Adapter/IO/Exception/RuntimeException.php');

$queue = 'hello-world';

class logz {
    function error($msg) {
        echo "\n" . date('c') . " ERROR: " . $msg;
    }
    function info($msg) {
        echo "\n" . date('c') . " INFO: " . $msg;
    }
}
$logger = new logz();

$logger->info('Starting');
$nsqsub = new \BackQ\Adapter\Nsq('127.0.0.1', 4150, ['logger' => $logger]);
$nsqsub->setWorkTimeout(5);
if ($nsqsub->connect()) {
    $logger->info('Connected');
    if ($nsqsub->bindRead($queue)) {
        $logger->info('Subscribed');
        $i = 100;
        while ($i > 0) {
            $logger->info('Picking task');
            $job = $nsqsub->pickTask();
            if ($job) {
                $logger->info('Got task: ' . json_encode($job));
                if (1 == rand(1,2)) {
                    $logger->info('Reporting success');
                    $nsqsub->afterWorkSuccess($job[0]);
                } else {
                    $logger->info('Reporting failure');
                    $nsqsub->afterWorkFailed($job[0]);
                }
            } else {
                $logger->info('No job received within work timeout');
            }
            $i--;
        }
    }
}
$logger->info('All done');
$nsqsub->disconnect();
$logger->info('Disconnected');
