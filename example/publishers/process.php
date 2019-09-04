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

include_once '../../vendor/autoload.php';

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    protected $queueName = 'abc';

    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $adapter = new \BackQ\Adapter\Beanstalk;

        $output  = new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG);
        $logger  = new \Symfony\Component\Console\Logger\ConsoleLogger($output);

        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher = MyProcessPublisher::getInstance();
if ($publisher->start()) {
    for ($i = 0; $i < 10; $i++) {
        $message = new \BackQ\Message\Process('echo ' . time() . '; echo $( date +%s ) >> /tmp/test');
        $result = $publisher->publish($message, [MyProcessPublisher::PARAM_JOBTTR    => 5,
                                                 MyProcessPublisher::PARAM_READYWAIT => 0]);
        if ($result > 0) {
            echo 'Published `' . $message->getCommandline() . '`` as ID=' . $result . ", check /tmp/test\n";
        }
        sleep(1);
    }
}
