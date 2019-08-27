<?php
namespace BackQ\Publisher;

abstract class Serialized extends AbstractPublisher
{
    protected $queueName = 'mydynamodbtablenameandsqsqueuename';
}
