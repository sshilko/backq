<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 */

include_once '../../../../../vendor/autoload.php';

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    protected $queueName = 'abc';

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $adapters = [new \BackQ\Adapter\Redis(), new \BackQ\Adapter\Beanstalk()];
        $adapter  = $adapters[array_rand($adapters)];
        echo 'Using ' . get_class($adapter) . ' adapter' . "\n";

        $output  = new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new \Symfony\Component\Console\Logger\ConsoleLogger($output);

        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyProcessPublisher::getInstance();
if ($publisher->start()) {
    for ($i = 0; $i < 5; $i++) {
        $message = new \BackQ\Message\Process('echo ' . time() . '; echo $( date +%s ) >> /tmp/test');
        $result = $publisher->publish($message);
        if ($result) {
            echo 'Published `' . $message->getCommandline() . '`` as ID=' . $result . ", check /tmp/test\n";
        } else {
            echo 'Failed to publish' . "\n";
        }
        sleep(1);
    }
}
