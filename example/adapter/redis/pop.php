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

/**
 * Example subscriber using
 * Adapter for Redis
 * @see https://redis.io
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$redisHost = getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('BACKQ_REDIS_PORT') ?: 6379);

$logger   = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$redissub = new Redis($logger, new RedisConfig(host: $redisHost, port: $redisPort));
$logger->info('Starting');
$redissub->setWorkTimeout(5);
if (!$redissub->connect()) {
    echo 'Failed to connect to redis at ' . $redisHost . ':' . $redisPort . "\n";
    exit(1);
}
$logger->info('Connected');
if ($redissub->bindRead($queue)) {
    $logger->info('Subscribed');
    $i = 100;
    while ($i > 0) {
        $logger->info('Picking task');
        $job = $redissub->pickTask();
        if ($job && $job[0]) {
            $logger->info('Got task: ' . json_encode($job));
            // the id is acknowledged as a string
            $workId = (string) $job[0];
            if (1 === rand(1, 2)) {
                $logger->info('Reporting success');
                $redissub->afterWorkSuccess($workId);
            } else {
                $logger->info('Reporting failure');
                $redissub->afterWorkFailed($workId);
            }
        } else {
            $logger->info('No job received within work timeout');
        }
        $i--;
    }
} else {
    $logger->error('Failed to bind to read queue ' . $queue);
    exit(1);
}
$logger->info('All done');
$redissub->disconnect();
$logger->info('Disconnected');
