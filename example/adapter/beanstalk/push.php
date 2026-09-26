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

$beanstalkdpub = new Beanstalk();
$beanstalkdpub->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)));
$beanstalkdpub->setWorkTimeout(5);
if (!$beanstalkdpub->connect($beanstalkdHost, $beanstalkdPort)) {
    echo 'Failed to connect to beanstalkd at ' . $beanstalkdHost . ':' . $beanstalkdPort . "\n";
    exit(1);
}
$beanstalkdpub->logInfo('Connected');
if ($beanstalkdpub->bindWrite($queue)) {
    $beanstalkdpub->logInfo('Ready to publish');
    $i = 100;
    while ($i > 0) {
        $randomMessage = 'Payload body of message ' . time();
        $jobId         = $beanstalkdpub->putTask($randomMessage);
        if ($jobId instanceof Throwable) {
            $beanstalkdpub->logError('Failed pushing message: ' . $jobId->getMessage());
        } else {
            $beanstalkdpub->logInfo('Pushed message ' . $jobId);
        }
        $i--;
        sleep(1);
    }
} else {
    $beanstalkdpub->logError('Failed to bind to write queue ' . $queue);
    exit(1);
}
$beanstalkdpub->logInfo('All done');
$beanstalkdpub->disconnect();
$beanstalkdpub->logInfo('Disconnected');
