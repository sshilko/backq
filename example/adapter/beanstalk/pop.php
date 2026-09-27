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

/**
 * Example subscriber using
 * Adapter for Beanstalkd
 * @see https://github.com/beanstalkd/beanstalkd
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$beanstalkdHost = getenv('BACKQ_BEANSTALKD_HOST') ?: '127.0.0.1';
$beanstalkdPort = (int) (getenv('BACKQ_BEANSTALKD_PORT') ?: 11300);

$logger        = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$beanstalkdsub = new Beanstalk($logger);
$logger->info('Starting');
$beanstalkdsub->setWorkTimeout(5);
if (!$beanstalkdsub->connect($beanstalkdHost, $beanstalkdPort)) {
    echo 'Failed to connect to beanstalkd at ' . $beanstalkdHost . ':' . $beanstalkdPort . "\n";
    exit(1);
}
$logger->info('Connected');
if ($beanstalkdsub->bindRead($queue)) {
    $logger->info('Subscribed');
    $i = 100;
    while ($i > 0) {
        $logger->info('Picking task');
        $job = $beanstalkdsub->pickTask();
        if ($job && $job[0]) {
            $logger->info('Got task: ' . json_encode($job));
            // the id is acknowledged as a string, beanstalkd reports one as an int
            $workId = (string) $job[0];
            if (1 === rand(1, 2)) {
                $logger->info('Reporting success');
                $beanstalkdsub->afterWorkSuccess($workId);
            } else {
                $logger->info('Reporting failure');
                $beanstalkdsub->afterWorkFailed($workId);
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
$beanstalkdsub->disconnect();
$logger->info('Disconnected');
