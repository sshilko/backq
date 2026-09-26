<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Nsq;
use BackQ\Publisher\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Process publisher backed by the NSQ adapter.
 *
 * Shared so that both the publisher and the serialized worker can load the
 * same class (the serialized message payload contains this publisher object).
 */
final class MyNsqProcessPublisher extends Process
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

    public static function createAdapter(): Nsq
    {
        $output   = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger   = new ConsoleLogger($output);
        $nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
        $nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

        $adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
        $adapter->setLogger($logger);

        return $adapter;
    }
}
