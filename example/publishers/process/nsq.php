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
 * Queues a process execution via NSQ
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/mynsqprocesspublisher.php';

$publisher = MyNsqProcessPublisher::getInstance();
if (!$publisher->start()) {
    echo 'Failed to start publisher, is nsqd at BACKQ_NSQD_HOST:BACKQ_NSQD_PORT (default 127.0.0.1:4150) reachable?' . "\n";
    exit(1);
}

$message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
try {
    $result = $publisher->publish($message);
} catch (Throwable $e) {
    echo 'Failed to publish process message via nsq adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($result) {
    /**
     * NSQ acknowledges a publish without returning a job id, so this is a boolean success
     */
    echo 'Published process message via nsq adapter, check /tmp/test' . "\n";
} else {
    echo 'Failed to publish process message via nsq adapter' . "\n";
    exit(1);
}