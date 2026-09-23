<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Beanstalk;
use BackQ\Message\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 *
 * Queues a process execution via Beanstalk
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib/myprocesspublisher.php';

$publisher = MyProcessPublisher::getInstance();
if ($publisher->start()) {
    for ($i = 0; $i < 5; $i++) {
        $message = new \BackQ\Message\Process('echo ' . time() . '; echo $( date +%s ) >> /tmp/test');
        $result = $publisher->publish($message);
        if ($result) {
            echo 'Published `' . $message->getCommandline() . '`` as ID=' . $result . ", check /tmp/test\n";
        } else {
            echo 'Failed to publish' . "\n";
        }
        sleep(1);
    }
}
