<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected $queueName = '456';

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $logger  = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new \BackQ\Adapter\Beanstalk;
        $adapter->setLogger($logger);

        return $adapter;
    }
}
