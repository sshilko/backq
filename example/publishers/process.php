<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Message\Process;
use Throwable;

/**
 * Publisher
 *
 * Queues a process execution via Beanstalk
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib/myprocesspublisher.php';

$publisher = new MyProcessPublisher(MyProcessPublisher::createAdapter());
if ($publisher->start()) {
    for ($i = 0; $i < 5; $i++) {
        $message = new \BackQ\Message\Process('echo ' . time() . '; echo $( date +%s ) >> /tmp/test');
        $result  = $publisher->publish($message);
        if ($result instanceof Throwable) {
            echo 'Failed to publish: ' . $result->getMessage() . "\n";
        } else {
            echo 'Published `' . $message->getCommandline() . '`` as ID=' . $result . ", check /tmp/test\n";
        }
        sleep(1);
    }
} else {
    echo 'Failed to start publisher, is beanstalkd running on 127.0.0.1:11300?' . "\n";
    exit(1);
}
