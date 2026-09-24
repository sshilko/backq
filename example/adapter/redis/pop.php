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

$redissub = new Redis($redisHost, $redisPort);
$redissub->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)));
$redissub->logInfo('Starting');
$redissub->setWorkTimeout(5);
if (!$redissub->connect()) {
    echo 'Failed to connect to redis at ' . $redisHost . ':' . $redisPort . "\n";
    exit(1);
}
$redissub->logInfo('Connected');
if ($redissub->bindRead($queue)) {
    $redissub->logInfo('Subscribed');
    $i = 100;
    while ($i > 0) {
        $redissub->logInfo('Picking task');
        $job = $redissub->pickTask();
        if ($job && $job[0]) {
            $redissub->logInfo('Got task: ' . json_encode($job));
            if (1 === rand(1, 2)) {
                $redissub->logInfo('Reporting success');
                $redissub->afterWorkSuccess($job[0]);
            } else {
                $redissub->logInfo('Reporting failure');
                $redissub->afterWorkFailed($job[0]);
            }
        } else {
            $redissub->logInfo('No job received within work timeout');
        }
        $i--;
    }
} else {
    $redissub->logError('Failed to bind to read queue ' . $queue);
    exit(1);
}
$redissub->logInfo('All done');
$redissub->disconnect();
$redissub->logInfo('Disconnected');