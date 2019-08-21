<?php
namespace BackQ\Publisher\WriteOnly;

use BackQ\Publisher\AbstractPublisher;

final class Scheduled extends AbstractPublisher
{
    protected $queueName = 'scheduled_process';
}
