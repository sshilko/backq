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

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_READYWAIT = \BackQ\Adapter\Redis::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        return new \BackQ\Adapter\Redis;
    }
}

/**
 * Optional adapter logger
 */
$logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
$adapter->setLogger($logger);

$publisher = \BackQ\Publisher\Process::getInstance($adapter);
if ($publisher->start() && $publisher->hasWorkers()) {
    $message = new \BackQ\Message\Process($command);
    $result  = $publisher->publish($message, [MyProcessPublisher::PARAM_READYWAIT => random_int(0, 2)]);
    if ($result > 0) {
        /**
         * Success
         */
    }
}
//$adapter->disconnect();