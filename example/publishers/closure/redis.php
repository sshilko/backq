<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Redis;
use BackQ\Publisher\Closure;
use Opis\Closure\SerializableClosure;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 *
 * Queues a closure execution via Redis
 * Publishes a job into default queue="closure"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MyRedisClosurePublisher extends Closure
{
    public const PARAM_READYWAIT = Redis::PARAM_READYWAIT;

    protected function setupAdapter(): AbstractAdapter
    {
        $output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new ConsoleLogger($output);

        $adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyRedisClosurePublisher::getInstance();
if (!$publisher->start()) {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT (default 127.0.0.1:6379) reachable?' . "\n";
    exit(1);
}

/**
 * Only statically scoped closures can be serialized and restored in the worker
 */
$closure  = new SerializableClosure(static function (): void {
    file_put_contents('/tmp/test', 'closure ' . time() . "\n", FILE_APPEND);
});
$message  = new \BackQ\Message\Closure($closure);
try {
    $result = $publisher->publish($message);
} catch (Throwable $e) {
    echo 'Failed to publish closure message via redis adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($result) {
    /**
     * Success
     */
    echo 'Published closure message via redis adapter as ID=' . $result . "\n";
} else {
    echo 'Failed to publish closure message via redis adapter' . "\n";
    exit(1);
}