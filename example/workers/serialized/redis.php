<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

use BackQ\Adapter\Redis;
use BackQ\Adapter\Redis\RedisConfig;
use BackQ\Worker\Serialized;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../publishers/lib/myredisprocesspublisher.php';

/**
 * Worker
 * Re-queue serialized messages, the inner publisher is restored from the payload
 */

$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$redisConfig = new RedisConfig(
    host: getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1',
    port: (int) (getenv('BACKQ_REDIS_PORT') ?: 6379),
);
$adapter = new Redis($logger, $redisConfig);

$worker = new Serialized($adapter, workTimeout: 1);
$worker->setLogger($logger);
$worker->setQueueName('serialized');
$worker->setRestartThreshold(100);
$worker->setIdleTimeout(100);
$worker->run();
