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
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Example publisher using
 * Adapter for Redis
 * @see https://redis.io
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$redisHost = getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('BACKQ_REDIS_PORT') ?: 6379);

$logger   = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$redispub = new Redis($logger, new RedisConfig(host: $redisHost, port: $redisPort));
$redispub->setWorkTimeout(5);
if (!$redispub->connect()) {
    echo 'Failed to connect to redis at ' . $redisHost . ':' . $redisPort . "\n";
    exit(1);
}
$logger->info('Connected');
if ($redispub->bindWrite($queue)) {
    $logger->info('Ready to publish');
    $i = 100;
    while ($i > 0) {
        $randomMessage = 'Payload body of message ' . time();
        $jobId         = $redispub->putTask($randomMessage);
        if ($jobId instanceof Throwable) {
            $logger->error('Failed pushing message: ' . $jobId->getMessage());
        } else {
            $logger->info('Pushed message ' . $jobId);
        }
        $i--;
        sleep(1);
    }
} else {
    $logger->error('Failed to bind to write queue ' . $queue);
    exit(1);
}
$logger->info('All done');
$redispub->disconnect();
$logger->info('Disconnected');
