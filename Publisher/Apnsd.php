<?php
namespace BackQ\Publisher;

class Apnsd extends AbstractPublisher
{
    public function getQueueName() {
        return 'apnsd';
    }
}