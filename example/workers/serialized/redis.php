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
use BackQ\Worker\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../publishers/lib/myredisprocesspublisher.php';

/**
 * Worker
 * Re-queue serialized messages, the inner publisher is restored from the payload
 */

$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

$worker = new Serialized($adapter);
$worker->setLogger($logger);
$worker->setQueueName('serialized');
$worker->setWorkTimeout(1);
$worker->setRestartThreshold(100);
$worker->setIdleTimeout(100);
$worker->run();