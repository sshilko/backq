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
use BackQ\Worker\Guzzle;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Worker
 *
 * Sends queued PSR-7 HTTP requests asynchronously from the default queue="guzzle"
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));

/**
 * Optional adapter logger
 */
$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

$worker = new Guzzle($adapter);
$worker->setLogger($logger);
$worker->setWorkTimeout(4);
$worker->setIdleTimeout(15);
$worker->setRestartThreshold(10);
$worker->run();