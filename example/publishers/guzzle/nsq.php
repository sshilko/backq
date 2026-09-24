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
use BackQ\Adapter\Nsq;
use BackQ\Publisher\Guzzle;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 *
 * Queues a PSR-7 HTTP request execution via NSQ
 * Publishes a job into default queue="guzzle"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MyNsqGuzzlePublisher extends Guzzle
{
    protected function setupAdapter(): AbstractAdapter
    {
        $output  = new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new ConsoleLogger($output);

        $nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
        $nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

        $adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
        $adapter->setLogger($logger);

        return $adapter;
    }
}

/**
 * The Guzzle worker sends the request asynchronously.
 * Point it at the demo HTTP server that ships with these examples:
 *
 *   docker compose exec -d app-php83 php -S 0.0.0.0:18080 example/http/server.php
 *
 * or override with BACKQ_GUZZLE_TARGET to use any other reachable endpoint.
 */
$target = getenv('BACKQ_GUZZLE_TARGET') ?: 'http://127.0.0.1:18080/ping';

$publisher = MyNsqGuzzlePublisher::getInstance();
if (!$publisher->start()) {
    echo 'Failed to start publisher, is nsqd at BACKQ_NSQD_HOST:BACKQ_NSQD_PORT (default 127.0.0.1:4150) reachable?' . "\n";
    exit(1);
}

$message = new \BackQ\Message\Guzzle(new Request('GET', $target));
try {
    $result = $publisher->publish($message);
} catch (Throwable $e) {
    echo 'Failed to publish guzzle message via nsq adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($result) {
    /**
     * NSQ acknowledges a publish without returning a job id, so this is a boolean success
     */
    echo 'Published guzzle request to ' . $target . ' via nsq adapter' . "\n";
} else {
    echo 'Failed to publish guzzle message via nsq adapter' . "\n";
    exit(1);
}