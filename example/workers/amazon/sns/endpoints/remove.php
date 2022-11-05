<?php

use BackQ\Adapter\Beanstalk;

chdir(__DIR__);
require_once '../endpoints.php';

/**
 * Remove AWS SNS endpoint
 */

$auth        = [];
$platform    = strtoupper(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
$setupWorker = new endpoints($platform, $auth);
$worker      = new BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Remove(new Beanstalk());

$worker->setQueueName($worker->getQueueName() . $setupWorker->getPlatform());
$worker->setClient($setupWorker->getClient());
$worker->setRestartThreshold(500);
$worker->setIdleTimeout(300);

$worker->run();
