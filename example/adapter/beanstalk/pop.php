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

/**
 * Example subscriber using
 * Adapter for Beanstalkd
 * @see https://github.com/beanstalkd/beanstalkd
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

$queue = 'hello-world';

$beanstalkdHost = getenv('BACKQ_BEANSTALKD_HOST') ?: '127.0.0.1';
$beanstalkdPort = (int) (getenv('BACKQ_BEANSTALKD_PORT') ?: 11300);

$beanstalkdsub = new Beanstalk();
$beanstalkdsub->setLogger(new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG)));
$beanstalkdsub->logInfo('Starting');
$beanstalkdsub->setWorkTimeout(5);
if (!$beanstalkdsub->connect($beanstalkdHost, $beanstalkdPort)) {
    echo 'Failed to connect to beanstalkd at ' . $beanstalkdHost . ':' . $beanstalkdPort . "\n";
    exit(1);
}
$beanstalkdsub->logInfo('Connected');
if ($beanstalkdsub->bindRead($queue)) {
    $beanstalkdsub->logInfo('Subscribed');
    $i = 100;
    while ($i > 0) {
        $beanstalkdsub->logInfo('Picking task');
        $job = $beanstalkdsub->pickTask();
        if ($job && $job[0]) {
            $beanstalkdsub->logInfo('Got task: ' . json_encode($job));
            if (1 === rand(1, 2)) {
                $beanstalkdsub->logInfo('Reporting success');
                $beanstalkdsub->afterWorkSuccess($job[0]);
            } else {
                $beanstalkdsub->logInfo('Reporting failure');
                $beanstalkdsub->afterWorkFailed($job[0]);
            }
        } else {
            $beanstalkdsub->logInfo('No job received within work timeout');
        }
        $i--;
    }
} else {
    $beanstalkdsub->logError('Failed to bind to read queue ' . $queue);
    exit(1);
}
$beanstalkdsub->logInfo('All done');
$beanstalkdsub->disconnect();
$beanstalkdsub->logInfo('Disconnected');
