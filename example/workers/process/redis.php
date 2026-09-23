<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Redis;
use BackQ\Worker\AProcess;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Worker
 *
 * Executes process jobs from the default queue="process"
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));

/**
 * Optional adapter logger
 */
$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

/**
 * OPTIONAL
 * Enforces re-queueing jobs reserved for >= int $seconds
 */
#$adapter->retryJobAfter(30);

$worker = new AProcess($adapter);
$worker->setIdleTimeout(15);
$worker->setRestartThreshold(10);
$worker->run();
