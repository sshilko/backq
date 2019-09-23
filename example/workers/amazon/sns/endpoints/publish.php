<?php
chdir(__DIR__);
require_once('../endpoints.php');

/**
 * Publishing to AWS SNS endpoint
 */

$auth        = [];
$platform    = strtoupper(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
$setupWorker = new endpoints($platform, $auth);
$worker      = new BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Publish(new \BackQ\Adapter\Beanstalk);

$worker->setQueueName($worker->getQueueName() . $setupWorker->getPlatform());
$worker->setClient($setupWorker->getClient());
$worker->setRestartThreshold(500);
$worker->setIdleTimeout(300);
$worker->run();
