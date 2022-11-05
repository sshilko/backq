<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use Backq\Adapter\AbstractAdapter;
use BackQ\Adapter\Beanstalk;
use BackQ\Publisher\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

final class MyProcessPublisher extends Process
{
    public const PARAM_JOBTTR    = Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = Beanstalk::PARAM_READYWAIT;

    protected $queueName = '456';

    protected function setupAdapter(): AbstractAdapter
    {
        $logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new Beanstalk();
        $adapter->setLogger($logger);

        return $adapter;
    }
}
