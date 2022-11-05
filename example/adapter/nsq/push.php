<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */
use BackQ\Adapter\Nsq;

/**
 * Example publisher using
 * Adapter for NSQ
 * @see http://nsq.io
 */
include_once '../../../src/Adapter/AbstractAdapter.php';
include_once '../../../src/Adapter/IO/AbstractIO.php';
include_once '../../../src/Adapter/IO/StreamIO.php';
include_once '../../../src/Adapter/Nsq.php';
include_once '../../../src/Adapter/IO/Exception/IOException.php';
include_once '../../../src/Adapter/IO/Exception/TimeoutException.php';
include_once '../../../src/Adapter/IO/Exception/RuntimeException.php';

$queue = 'hello-world';

$nsqpub = new Nsq('127.0.0.1', 4150, ['persistent' => false]);
$nsqpub->setWorkTimeout(5);
if ($nsqpub->connect()) {
    $nsqpub->logInfo('Connected');
    if ($nsqpub->bindWrite($queue)) {
        $nsqpub->logInfo('Ready to publish');
        $i = 100;
        while ($i > 0) {
            $randomMessage = 'Payload body of message ' . time();
            if ($nsqpub->putTask($randomMessage)) {
                $nsqpub->logInfo('Pushed message');
            } else {
                $nsqpub->logError('Failed pushing message message');
            }
            $i--;
            sleep(1);
        }
    }
}
$nsqpub->logInfo('All done');
$nsqpub->disconnect();
$nsqpub->logInfo('Disconnected');
