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
 * Delays `Process` message execution via Serialized worker
 */

include_once '../../vendor/autoload.php';
include_once 'lib/myprocesspublisher.php';

final class MySerializedPublisher extends \BackQ\Publisher\Serialized
{
    //public const PARAM_MESSAGE_ID = \BackQ\Adapter\DynamoSQS::PARAM_MESSAGE_ID;
    //public const PARAM_READYWAIT  = \BackQ\Adapter\DynamoSQS::PARAM_READYWAIT;

    public const PARAM_READYWAIT  = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;
    public const PARAM_JOBTTR     = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;

    protected $queueName = '123';

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $output = new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG);
        $logger = new \Symfony\Component\Console\Logger\ConsoleLogger($output);
        //$adapter = new \BackQ\Adapter\DynamoSQS(1, 'apiKey1', 'secretKey1', 'us-east-1');
        $adapter = new \BackQ\Adapter\Beanstalk;
        $adapter->setLogger($logger);

        return $adapter;
    }
}

/**
 * We will serialize and delay `process` message
 */
$processPublisher      = MyProcessPublisher::getInstance();
$processMessage        = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = [MyProcessPublisher::PARAM_JOBTTR    => 5,
                          MyProcessPublisher::PARAM_READYWAIT => 1];


/**
 * Delay via serialized message/worker
 */
$publisher      = MySerializedPublisher::getInstance();
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = [MySerializedPublisher::PARAM_JOBTTR     => 10,
                   MySerializedPublisher::PARAM_READYWAIT  => 1];

$response = null;
if ($publisher->start()) {
    $response = $publisher->publish($message, $publishOptions);
    echo 'Published process message via serialized message for long delay as ID=' . $response . "\n";
}