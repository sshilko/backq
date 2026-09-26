<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Nsq;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Example publisher using
 * Adapter for NSQ
 * @see http://nsq.io
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
$nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

$nsqpub = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
$nsqpub->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)));
$nsqpub->setWorkTimeout(5);
if (!$nsqpub->connect()) {
    echo 'Failed to connect to nsqd at ' . $nsqdHost . ':' . $nsqdPort . "\n";
    exit(1);
}
$nsqpub->logInfo('Connected');
if ($nsqpub->bindWrite($queue)) {
        $nsqpub->logInfo('Ready to publish');
        $i = 100;
        while ($i > 0) {
            $randomMessage = 'Payload body of message ' . time();
            $error         = $nsqpub->putTask($randomMessage);
            if ($error instanceof Throwable) {
                $nsqpub->logError('Failed pushing message: ' . $error->getMessage());
            } else {
                $nsqpub->logInfo('Pushed message');
            }
            $i--;
            sleep(1);
        }
    } else {
        $nsqpub->logError('Failed to bind to write queue ' . $queue);
        exit(1);
    }
$nsqpub->logInfo('All done');
$nsqpub->disconnect();
$nsqpub->logInfo('Disconnected');
