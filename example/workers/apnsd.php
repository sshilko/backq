<?php
/**
 * Worker
 *
 * Send APNS (Apple Push Notifications)
 * Launches a worker that listens for jobs on queue="apnsd-myapp1"
 * 
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */

$app = '-myapp1';
$env = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;
$logFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR .  'backq.apns.' . $env . '.' . $app . '.log';

$pem = 'myapp_apns_certificate.pem';
$ca  = 'entrust_2048_ca.cer';

$worker = new \BackQ\Worker\Apnsd(new \BackQ\Adapter\Beanstalk);
$worker->setLogger(new \BackQ\Logger($logFilePath));
$worker->setRootCertificationAuthority($ca);
$worker->setCertificate($pem);
$worker->setEnvironment($env);
$worker->setQueueName($worker->getQueueName() . $app);

$worker->setRestartThreshold(250);
$worker->setIdleTimeout(180);
if (in_array(PHP_VERSION_ID, array('50523', '50524', '50607', '50608'))) {
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
$worker->socketSelectTimeout = \BackQ\Worker\Apnsd::SENDSPEED_TIMEOUT_RECOMMENDED;
$worker->readWriteTimeout    = 3;

$worker->run();