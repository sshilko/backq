<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Redis;
use BackQ\Publisher\Process;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Process publisher backed by the Redis adapter.
 *
 * Shared so that both the publisher and the serialized worker can load the
 * same class (the serialized message payload contains this publisher object).
 */
final class MyRedisProcessPublisher extends Process
{
    public const PARAM_READYWAIT = Redis::PARAM_READYWAIT;

    protected $queueName = 'process';

    protected function setupAdapter(): AbstractAdapter
    {
        $output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new ConsoleLogger($output);

        $adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
        $adapter->setLogger($logger);

        return $adapter;
    }
}