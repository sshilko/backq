<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Publisher
 *
 * Queues a process execution via Redis
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/myredisprocesspublisher.php';

$publisher = new MyRedisProcessPublisher(MyRedisProcessPublisher::createAdapter());
if (!$publisher->start()) {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT (default 127.0.0.1:6379) reachable?' . "\n";
    exit(1);
}

$message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
try {
    $jobId = $publisher->publish($message, readyWait: random_int(0, 2));
} catch (Throwable $e) {
    echo 'Failed to publish process message via redis adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($jobId instanceof Throwable) {
    echo 'Failed to publish process message via redis adapter: ' . $jobId->getMessage() . "\n";
    exit(1);
}
/**
 * Success
 */
echo 'Published process message via redis adapter as ID=' . $jobId . "\n";