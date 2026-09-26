<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Nsq;
use BackQ\Publisher\Closure;
use Opis\Closure\SerializableClosure;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Publisher
 *
 * Queues a closure execution via NSQ
 * Publishes a job into default queue="closure"
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MyNsqClosurePublisher extends Closure
{
}

$logger   = new ConsoleLogger(new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG));
$nsqdHost = getenv('BACKQ_NSQD_HOST') ?: '127.0.0.1';
$nsqdPort = (int) (getenv('BACKQ_NSQD_PORT') ?: 4150);

$adapter = new Nsq($nsqdHost, $nsqdPort, ['persistent' => false]);
$adapter->setLogger($logger);

$publisher = new MyNsqClosurePublisher($adapter);
if (!$publisher->start()) {
    echo 'Failed to start publisher, is nsqd at BACKQ_NSQD_HOST:BACKQ_NSQD_PORT (default 127.0.0.1:4150) reachable?' . "\n";
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
    $error = $publisher->publish($message);
} catch (Throwable $e) {
    echo 'Failed to publish closure message via nsq adapter: ' . $e->getMessage() . "\n";
    exit(1);
}
if ($error instanceof Throwable) {
    echo 'Failed to publish closure message via nsq adapter: ' . $error->getMessage() . "\n";
    exit(1);
}
/**
 * NSQ acknowledges a publish without returning a job id, so a null result is the success
 */
echo 'Published closure message via nsq adapter, check /tmp/test' . "\n";