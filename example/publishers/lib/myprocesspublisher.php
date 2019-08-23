<?php
final class MyProcessPublisher extends \BackQ\Publisher\Process
{
    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected $queueName = '456';

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        $logger  = new \Symfony\Component\Console\Logger\ConsoleLogger(new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_DEBUG));
        $adapter = new \BackQ\Adapter\Beanstalk;
        $adapter->setLogger($logger);

        return $adapter;
    }
}
