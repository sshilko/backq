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
use BackQ\Adapter\Redis;
use BackQ\Worker\AProcess;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

include_once '../../../../../vendor/autoload.php';

/**
 * Worker
 *
 * Execute processes jobs from the queue
 * Launches a worker that listens for jobs on default queue="process"
 */

$output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
$logger  = new ConsoleLogger($output);

$adapters = [new Redis(), new Beanstalk()];
$adapter  = $adapters[array_rand($adapters)];
echo 'Using ' . get_class($adapter) . ' adapter' . "\n";

$worker = new AProcess($adapter);
$worker->setLogger($logger);
$worker->setWorkTimeout(5);
$worker->setIdleTimeout(12);
$worker->setQueueName('abc');
$worker->run();
