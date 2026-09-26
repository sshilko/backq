<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
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
}

$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
$adapter->setLogger($logger);

$publisher = new MyRedisClosurePublisher($adapter);
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
    $jobId = $publisher->publish($message);
} catch (Throwable $e) {
    echo 'Failed to publish closure message via redis adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($jobId instanceof Throwable) {
    echo 'Failed to publish closure message via redis adapter: ' . $jobId->getMessage() . "\n";
    exit(1);
}
/**
 * Success
 */
echo 'Published closure message via redis adapter as ID=' . $jobId . "\n";