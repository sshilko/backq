<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

use BackQ\Adapter\Beanstalk;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Example publisher using
 * Adapter for Beanstalkd
 * @see https://github.com/beanstalkd/beanstalkd
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$beanstalkdHost = getenv('BACKQ_BEANSTALKD_HOST') ?: '127.0.0.1';
$beanstalkdPort = (int) (getenv('BACKQ_BEANSTALKD_PORT') ?: 11300);

$logger        = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$beanstalkdpub = new Beanstalk($logger);
$beanstalkdpub->setWorkTimeout(5);
if (!$beanstalkdpub->connect($beanstalkdHost, $beanstalkdPort)) {
    echo 'Failed to connect to beanstalkd at ' . $beanstalkdHost . ':' . $beanstalkdPort . "\n";
    exit(1);
}
$logger->info('Connected');
if ($beanstalkdpub->bindWrite($queue)) {
    $logger->info('Ready to publish');
    $i = 100;
    while ($i > 0) {
        $randomMessage = 'Payload body of message ' . time();
        $jobId         = $beanstalkdpub->putTask($randomMessage);
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
$beanstalkdpub->disconnect();
$logger->info('Disconnected');
