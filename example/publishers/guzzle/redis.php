<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Redis;
use BackQ\Publisher\Guzzle;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * Publisher
 *
 * Queues a PSR-7 HTTP request execution via Redis
 * Publishes a job into default queue="guzzle"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MyRedisGuzzlePublisher extends Guzzle
{
}

$logger  = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$adapter = new Redis(getenv('BACKQ_REDIS_HOST') ?: '127.0.0.1', (int) (getenv('BACKQ_REDIS_PORT') ?: 6379));
$adapter->setLogger($logger);

/**
 * The Guzzle worker sends the request asynchronously.
 * Point it at the demo HTTP server that ships with these examples:
 *
 *   docker compose exec -d app-php83 php -S 0.0.0.0:18080 example/http/server.php
 *
 * or override with BACKQ_GUZZLE_TARGET to use any other reachable endpoint.
 */
$target = getenv('BACKQ_GUZZLE_TARGET') ?: 'http://127.0.0.1:18080/ping';

$publisher = new MyRedisGuzzlePublisher($adapter);
if (!$publisher->start()) {
    echo 'Failed to start publisher, is redis at BACKQ_REDIS_HOST:BACKQ_REDIS_PORT (default 127.0.0.1:6379) reachable?' . "\n";
    exit(1);
}

$message = new \BackQ\Message\Guzzle(new Request('GET', $target));
try {
    $jobId = $publisher->publish($message, readyWait: random_int(0, 2));
} catch (Throwable $e) {
    echo 'Failed to publish guzzle message via redis adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($jobId instanceof Throwable) {
    echo 'Failed to publish guzzle message via redis adapter: ' . $jobId->getMessage() . "\n";
    exit(1);
}
/**
 * Success
 */
echo 'Published guzzle request to ' . $target . ' via redis adapter as ID=' . $jobId . "\n";