<?php
/**
 * Publisher
 *
 * Queues APNS (Apple Push Notifications)
 * Publishes a jobs to queue="apnsd-myapp1"
 * 
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 */
include_once '../../vendor/autoload.php';

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
$messagesQ = array($app => array($message));

$publisher = \BackQ\Publisher\Apnsd::getInstance(new \BackQ\Adapter\Beanstalk);
$queueName = $publisher->getQueueName();

/**
 * Give 4 seconds to dispatch the message (time to run)
 * Delay each job by 1 second
 */
$params = array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => 4,
                \BackQ\Adapter\Beanstalk::PARAM_READYWAIT => 1);

foreach ($messagesQ as $app => $messages) {
    /**
     * Cant just switch to different queue, have to make a new instance
     */
    $publisher->setQueueName($queueName . $app);
    $unpublished = $messages;

    if ($publisher->start() && $publisher->hasWorkers()) {
        /**
         * Send into queue
         */
        for ($i = 0; $i < count($messages); $i++) {
            $result = $publisher->publish($messages[$i], $params);
            if ($result > 0) {
                unset($unpublished[$i]);
            } else {
                @error_log('Failed to publish apns asynchronously via apnsd');
            }
        }
    }

    if (!empty($unpublished)) {
        /**
         * Fallback to send $unpublished
         */
    }
}
