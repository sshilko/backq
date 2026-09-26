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
use BackQ\Message\GuzzleForwarder;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Publisher
 *
 * Queues a process execution via Beanstalk
 * Publishes a job into default queue="process"
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib/myguzzlepublisher.php';


$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter = new Beanstalk();
$adapter->setLogger($logger);

$publisher = new MyGuzzlePublisher($adapter);
if ($publisher->start()) {
    for ($i = 0; $i < 5; $i++) {
        $message = new \BackQ\Message\GuzzleForwarder(new GuzzleHttp\Psr7\Request('GET', 'https://1.1.1.1'));
        $result  = $publisher->publish($message);
        if ($result instanceof Throwable) {
            echo 'Failed to publish: ' . $result->getMessage() . "\n";
        } else {
            echo 'Published as ID=' . $result . "\n";
        }
        sleep(1);
    }
} else {
    echo 'Failed to start publisher, is beanstalkd running on 127.0.0.1:11300?' . "\n";
    exit(1);
}
