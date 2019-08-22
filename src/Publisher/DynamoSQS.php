<?php
namespace BackQ\Publisher;

final class DynamoSQS extends AbstractPublisher
{
    protected $queueName = 'scheduled_process';
}
