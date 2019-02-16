<?php
/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */

include_once '../../../vendor/autoload.php';

$command = 'echo $( date +%s ) >> /tmp/test';

$adapter = new \BackQ\Adapter\Redis;

/**
 * Optional adapter logger
 */
$logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);
/**
 * This value is queue dependent and should equal >= max expected job time
 * In case worker dies, job will stay in "reserved" state until get's retried (if enabled via retryJobAfter())
 * if not enabled, failed jobs then never be retried - stuck forever in reserved
 */
$adapter->retryJobAfter(30);

$worker = new \BackQ\Worker\AProcess($adapter);
$worker->setIdleTimeout(15);
$worker->setRestartThreshold(10);
$worker->run();