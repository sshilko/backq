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
use BackQ\Worker\AProcess;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Worker
 *
 * Executes process jobs from the default queue="process"
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
$nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

$adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);

/**
 * Optional adapter logger
 */
$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

$worker = new AProcess($adapter);
$worker->setLogger($logger);
$worker->setWorkTimeout(5);
$worker->setIdleTimeout(15);
$worker->setRestartThreshold(10);
$worker->run();