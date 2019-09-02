<?php
/**
 * Publisher
 *
 * Queues a process execution
 * Publishes a job into default queue="process"
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */

include_once '../../vendor/autoload.php';
include_once 'lib/myprocesspublisher.php';

final class MySerializedPublisher extends \BackQ\Publisher\Serialized
{
    //public const PARAM_MESSAGE_ID = \BackQ\Adapter\DynamoSQS::PARAM_MESSAGE_ID;
    //public const PARAM_READYWAIT  = \BackQ\Adapter\DynamoSQS::PARAM_READYWAIT;

    public const PARAM_READYWAIT  = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected $queueName = '123';

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
        //$adapter = new \BackQ\Adapter\DynamoSQS(1, 'apiKey1', 'secretKey1', 'us-east-1');
        $adapter = new \BackQ\Adapter\Beanstalk;
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$processPublisher      = MyProcessPublisher::getInstance();
$processMessage        = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
$processPublishOptions = [MyProcessPublisher::PARAM_JOBTTR    => 5,
                          MyProcessPublisher::PARAM_READYWAIT => 1];


$publisher      = MySerializedPublisher::getInstance();
$message        = new \BackQ\Message\Serialized($processMessage, $processPublisher, $processPublishOptions);
$publishOptions = [//MySerializedPublisher::PARAM_MESSAGE_ID => $publisher1->getQueueName(),
                   MySerializedPublisher::PARAM_READYWAIT  => 1];

$serializedMessage = serialize($message);
$originalMessage   = unserialize($serializedMessage);

$response = null;
if ($publisher->start()) {
    $response = $publisher->publish($originalMessage, $publishOptions);
    var_dump($response);
}