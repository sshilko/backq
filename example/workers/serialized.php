<?php

include_once '../../vendor/autoload.php';
include_once '../publishers/lib/myprocesspublisher.php';

/**
 * Worker
 * Re-queue serialized messages
 */

$worker = new \BackQ\Worker\Serialized(new \BackQ\Adapter\Beanstalk);
$logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
$worker->setLogger($logger);
$worker->setQueueName('123');
$worker->setWorkTimeout(1);
$worker->setRestartThreshold(100);
$worker->setIdleTimeout(100);
$worker->run();
