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
use BackQ\Publisher\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

final class MyProcessPublisher extends Process
{
    protected string $queueName = 'process';

    /**
     * This publisher is embedded into a `Message\Serialized` payload, an adapter
     * is not serializable so it has to be rebuilt when the worker restores it
     */
    public function __wakeup(): void
    {
        $this->adapter = self::createAdapter();
    }

    public static function createAdapter(): Beanstalk
    {
        $logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new Beanstalk();
        $adapter->setLogger($logger);

        return $adapter;
    }
}
