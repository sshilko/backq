<?php
/**
 * Example publisher using
 * Adapter for NSQ
 * @see http://nsq.io
 */
include_once('../../../src/Adapter/AbstractAdapter.php');
include_once('../../../src/Adapter/IO/AbstractIO.php');
include_once('../../../src/Adapter/IO/StreamIO.php');
include_once('../../../src/Adapter/Nsq.php');

$queue = 'hello-world';

class logz {
    function error($msg) {
        echo "\n" . date('c') . " ERROR: " . $msg;
    }
    function info($msg) {
        echo "\n" . date('c') . " INFO: " . $msg;
    }
}
$logger = new logz();

$logger->info('Starting');
$nsqpub = new \BackQ\Adapter\Nsq('127.0.0.1', 4150, ['debug' => true, 'logger' => $logger]);
$nsqpub->setWorkTimeout(5);
if ($nsqpub->connect()) {
    $logger->info('Connected');
    if ($nsqpub->bindWrite($queue)) {
        $logger->info('Ready to publish');
        $i = 100;
        while ($i > 0) {
            $randomMessage = 'Payload body of message ' . time();
            if ($nsqpub->putTask($randomMessage)) {
                $logger->info('Pushed message');
            } else {
                $logger->error('Failed pushing message message');
            }
            $i--;
            sleep(1);
        }
    }
}
$logger->info('All done');
$nsqpub->disconnect();
$logger->info('Disconnected');

