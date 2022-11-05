<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Beanstalk;
use BackQ\Worker\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

include_once '../../../../../vendor/autoload.php';
include_once '../publishers/lib/myprocesspublisher.php';

/**
 * Worker
 * Re-queue serialized messages
 */

$worker = new Serialized(new Beanstalk());
$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$worker->setLogger($logger);
$worker->setQueueName('123');
$worker->setWorkTimeout(1);
$worker->setRestartThreshold(100);
$worker->setIdleTimeout(100);
$worker->run();
