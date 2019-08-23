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

final class MySerializedPublisher extends \BackQ\Publisher\Serialized
{
    public const PARAM_MESSAGE_ID = \BackQ\Adapter\DynamoSQS::PARAM_MESSAGE_ID;
    public const PARAM_READYWAIT  = \BackQ\Adapter\DynamoSQS::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new \BackQ\Adapter\DynamoSQS(1, 'apiKey1', 'secretKey1', 'us-east-1');
        $adapter->setPickBatchSize(1);
        $adapter->setLogger($logger);

        return $adapter;
    }
}

final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $logger = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new \BackQ\Adapter\Beanstalk;
        $adapter->setLogger($logger);

        return $adapter;
    }
}

$publisher1      = MyProcessPublisher::getInstance();
$message1        = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
$publishOptions1 = [MyProcessPublisher::PARAM_JOBTTR    => 5,
                    MyProcessPublisher::PARAM_READYWAIT => 1];


$publisher2      = MySerializedPublisher::getInstance();
$message2        = new \BackQ\Message\Serialized($message1, $publisher1, $publishOptions1);
$publishOptions2 = [MySerializedPublisher::PARAM_MESSAGE_ID => $publisher1->getQueueName(),
                    MySerializedPublisher::PARAM_READYWAIT  => 1];

$message3 = serialize($message2);
$message4 = unserialize($message3);

$response = null;
if ($publisher2->start()) {
    $response = $publisher2->publish($message4, $publishOptions2);
    var_dump($response);
}