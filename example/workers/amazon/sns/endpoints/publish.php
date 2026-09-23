<?php

use BackQ\Adapter\Beanstalk;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Publish;

require_once __DIR__ . '/../../../../../vendor/autoload.php';
require_once __DIR__ . '/../Endpoints.php';

/**
 * Publishing to AWS SNS endpoint
 *
 * Usage: php publish.php [platform]
 * Platform defaults to the BACKQ_SNS_PLATFORM environment variable or 'gcm'
 * (one of: apns, apns_sandbox, baidu, gcm)
 */

$auth        = ['region' => getenv('AWS_REGION') ?: 'us-east-1', 'version' => 'latest'];
$platform    = strtoupper($argv[1] ?? getenv('BACKQ_SNS_PLATFORM') ?: 'gcm');
$setupWorker = new Endpoints($platform, $auth);
$worker      = new Publish(new Beanstalk());

$worker->setQueueName($worker->getQueueName() . $setupWorker->getPlatform());
$worker->setClient($setupWorker->getClient());
$worker->setRestartThreshold(300);
$worker->setIdleTimeout(300);

$worker->run();