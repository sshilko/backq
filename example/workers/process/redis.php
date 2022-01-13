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
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 */
include_once '../../../../../../vendor/autoload.php';

$command = 'echo $( date +%s ) >> /tmp/test';

$adapter = new \BackQ\Adapter\Redis;

/**
 * Optional adapter logger
 */
$logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

/**
 * OPTIONAL
 * Enforces re-queueing jobs reserved for >= int $seconds
 */
#$adapter->retryJobAfter(30);

$worker = new \BackQ\Worker\AProcess($adapter);
$worker->setIdleTimeout(15);
$worker->setRestartThreshold(10);
$worker->run();
