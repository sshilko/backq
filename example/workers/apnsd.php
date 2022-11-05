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
use BackQ\Logger;
use BackQ\Worker\Apnsd;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Worker
 *
 * Send APNS (Apple Push Notifications) from the queue
 * Launches a worker that listens for jobs on queue="apnsd-myapp1"
 */

include_once '../../../../../vendor/autoload.php';

$app = '-myapp1';
$env = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;
$logFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backq.apns.' . $env . '.' . $app . '.log';

$pem = 'myapp_apns_certificate.pem';
$ca  = 'entrust_2048_ca.cer';

$worker = new Apnsd(new Beanstalk());

$logger = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$worker->setLogger($logger);

$worker->setPushLogger(new Logger($logFilePath));
$worker->setRootCertificationAuthority($ca);
$worker->setCertificate($pem);
$worker->setEnvironment($env);
$worker->setQueueName($worker->getQueueName() . $app);

$worker->setRestartThreshold(250);
$worker->setIdleTimeout(180);
if (in_array(PHP_VERSION_ID, ['50523', '50524', '50607', '50608'])) {
    /**
     * 5.5.23 does not honor fread() timeout
     * @see https://bugs.php.net/bug.php?id=69393
     * bugfix expected 5.5.25 & 5.6.9
     */
    $worker->connectTimeout = 1;
}
/**
 * The lower (faster) the bigger chances we send a message into already closed socket
 */
$worker->socketSelectTimeout = Apnsd::SENDSPEED_TIMEOUT_RECOMMENDED;
$worker->readWriteTimeout    = 3;

$worker->run();
