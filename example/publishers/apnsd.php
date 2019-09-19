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
 * APNS Publisher
 * Queues APNS (Apple Push Notifications)
 */
include_once '../../vendor/autoload.php';

class MyApnsdPublisher extends \BackQ\Publisher\Apnsd
{
    public const PARAM_JOBTTR    = \BackQ\Adapter\Beanstalk::PARAM_JOBTTR;
    public const PARAM_READYWAIT = \BackQ\Adapter\Beanstalk::PARAM_READYWAIT;

    protected function setupAdapter(): \Backq\Adapter\AbstractAdapter
    {
        return new \BackQ\Adapter\Beanstalk;
    }
}

$iostokn = '1e82db91c7ceddd72bf33d74ae052ac9c84a065b35148ac401388843106a7485';
$message = new ApnsPHP_Message($iostokn);
$message->setCustomIdentifier("Message-Badge-3");
$message->setBadge(3);
$message->setText('Hello APNs-enabled device!');
$message->setSound();
$message->setCustomProperty('acme2', array('bang', 'whiz'));
$message->setCustomProperty('acme3', array('bing', 'bong'));
$message->setExpiry(30);

$app       = '-myapp1';
$messagesQ = [$app => [$message]];

$publisher = MyApnsdPublisher::getInstance();
$queueName = $publisher->getQueueName();

/**
 * Give 4 seconds to dispatch the message (time to run)
 * Delay each job by 1 second
 */
$params = [MyApnsdPublisher::PARAM_JOBTTR    => 4,
           MyApnsdPublisher::PARAM_READYWAIT => 1];

foreach ($messagesQ as $app => $messages) {
    echo 'Publishing message: ' . $message->getText() . "\n";
    /**
     * Cant just switch to different queue, have to make a new instance
     */
    $publisher->setQueueName($queueName . $app);
    $unpublished = $messages;

    if ($publisher->start() //&& $publisher->hasWorkers()
       ) {
        echo 'Connected and maybe has workers' . "\n";
        /**
         * Send into queue
         */
        $countMessages = count($messages);
        for ($i = 0; $i < $countMessages; $i++) {
            $result = $publisher->publish($messages[$i], $params);
            echo 'Published into queue as id=' . $result . "\n";
            if ($result) {
                unset($unpublished[$i]);
            } else {
                echo 'Failed to publish apns asynchronously via apnsd';
            }
        }
    }

    if (!empty($unpublished)) {
        /**
         * Fallback to send $unpublished
         */
    }
}
