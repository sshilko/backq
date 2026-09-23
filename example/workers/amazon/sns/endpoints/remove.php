<?php

use BackQ\Adapter\Beanstalk;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Remove;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../../../../../vendor/autoload.php';
require_once __DIR__ . '/../Endpoints.php';

/**
 * Remove AWS SNS endpoint
 *
 * Usage: php remove.php [platform]
 * Platform defaults to the BACKQ_SNS_PLATFORM environment variable or 'gcm'
 * (one of: apns, apns_sandbox, baidu, gcm)
 */

$auth        = ['region' => getenv('AWS_REGION') ?: 'us-east-1', 'version' => 'latest'];
$platform    = strtoupper($argv[1] ?? getenv('BACKQ_SNS_PLATFORM') ?: 'gcm');
$setupWorker = new Endpoints($platform, $auth);

$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter = new Beanstalk();
$adapter->setLogger($logger);

$worker      = new Remove($adapter);
$worker->setLogger($logger);

$worker->setQueueName($worker->getQueueName() . $setupWorker->getPlatform());
$worker->setClient($setupWorker->getClient());
$worker->setRestartThreshold(300);
$worker->setIdleTimeout(300);

$worker->run();