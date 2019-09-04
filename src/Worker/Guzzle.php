<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker;

final class Guzzle extends AbstractWorker
{
    protected $queueName = 'guzzle';
    public $workTimeout  = 4;

    public function run()
    {
        $connected = $this->start();
        $this->logDebug('started');
        $push = null;
        if ($connected) {
            try {
                $client  = new \GuzzleHttp\Client();
                $this->logDebug('connected to queue');

                $work = $this->work();
                $this->logDebug('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->logDebug('got some work: ' . ($payload ? 'yes' : 'no'));

                    if (!$payload && $this->workTimeout > 0) {
                        continue;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\Guzzle)) {
                        /**
                         * Nothing to do + report as a success
                         */
                        $work->send($processed);
                        $this->logDebug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }
                    try {
                        $me = $this;

                        $request = $message->getRequest();
                        $promise = $client->sendAsync($request)->then(
                            function ($fulfilledResponse) use ($me) {
                            /** @var $fulfilledResponse \GuzzleHttp\Psr7\Response */
                            $me->logDebug('Request sent, got response ' . $fulfilledResponse->getStatusCode() .
                                         ' ' . json_encode((string)    $fulfilledResponse->getBody()));
                            },
                            function ($rejectedResponse) use ($me) {
                                /** @var $rejectedResponse \GuzzleHttp\Exception\RequestException */
                                $me->logDebug('Request sent, FAILED with ' . $rejectedResponse->getMessage());
                            });

                        $promise->wait();
                    } catch (\Exception $e) {
                        error_log('Error while sending FCM: ' . $e->getMessage());
                    } finally {
                        /**
                         * If using Beanstalk and not returned success after TTR time,
                         * the job considered failed and is put back into pool of "ready" jobs
                         */
                        $work->send((true === $processed));
                    }
                }
            } catch (\Exception $e) {
                $this->logDebug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
