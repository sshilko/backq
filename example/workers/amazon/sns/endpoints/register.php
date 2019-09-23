<?php
chdir(__DIR__);
require_once('../endpoints.php');

/**
 * Register AWS SNS endpoint
 */

$auth        = [];
$platform    = strtoupper(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
$setupWorker = new endpoints($platform, $auth);
$worker      = new BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Register(new \BackQ\Adapter\Beanstalk);

$worker->setQueueName($worker->getQueueName() . $setupWorker->getPlatform());
$worker->setClient($setupWorker->getClient());
$worker->setRestartThreshold(300);
$worker->setIdleTimeout(300);

$worker->run();
