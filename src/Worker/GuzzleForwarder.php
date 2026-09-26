<?php

declare(strict_types=1);

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker;

use BackQ\Message\GuzzleForwarder as MessageGuzzleForwarder;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use function date;
use function gettype;
use function is_string;
use function json_encode;
use function trigger_error;
use function unserialize;
use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;

abstract class GuzzleForwarder extends AbstractWorker
{
    #[Override]
    public function run(): void
    {
        $connected = $this->start();
        $this->logDebug('started');
        if ($connected) {
            try {
                $client  = new Client();
                $this->logDebug('connected to queue');

                $work = $this->work();
                $this->logDebug('after init work generator');

                /**
                 * @phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
                 */
                foreach ($work as $_ => $payload) {
                    $this->logDebug('got some work: ' . ($payload ? 'yes' : 'no'));

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        $work->send(true);

                        continue;
                    }

                    if (!is_string($payload)) {
                        $work->send(true);
                        $this->logDebug('Worker does not support payload of: ' . gettype($payload));

                        continue;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof MessageGuzzleForwarder)) {
                        /**
                         * Nothing to do + report as a success
                         */
                        $work->send($processed);
                        $this->logDebug('Worker does not support payload of: ' . gettype($message));

                        continue;
                    }

                    if (!$message->isReady()) {
                        /**
                         * Message should not be processed now
                         */
                        $work->send(false);

                        continue;
                    }

                    if ($message->isExpired()) {
                        $work->send(true);

                        continue;
                    }
                    $requestCallback = $message->getCallback();

                    try {
                        $me = $this;

                        $request = $message->getRequest();
                        $options = [
                            RequestOptions::ON_STATS => static function (TransferStats $stats) use ($requestCallback): void {
                                /**
                                 * Callable invoked when the handler finished sending the request
                                 */
                                if ($stats->hasResponse() && $requestCallback) {
                                    $callback = $requestCallback->getClosure();
                                    $callback($stats->getResponse());
                                }
                            },
                            RequestOptions::TIMEOUT  => $message->getTimeout(),
                        ];

                        $promise = $client->sendAsync($request, $options)->then(
                            static function (ResponseInterface $fulfilledResponse) use ($me): void {
                                $me->logDebug('Request sent, got response ' . $fulfilledResponse->getStatusCode() .
                                    ' ' . json_encode((string) $fulfilledResponse->getBody(), JSON_THROW_ON_ERROR));
                            },
                            static function (Throwable $rejectedResponse) use ($me): void {
                                $me->logDebug('Request sent, FAILED with ' . $rejectedResponse->getMessage());
                            }
                        );

                        $promise->wait();
                    } catch (\Throwable $e) {
                        trigger_error(self::class . ' worker exception: ' . $e->getMessage(), E_USER_WARNING);
                    } finally {
                        /**
                         * If using Beanstalk and not returned success after TTR time,
                         * the job considered failed and is put back into pool of "ready" jobs
                         */
                        $work->send((true === $processed));
                    }
                }
            } catch (\Throwable $e) {
                $this->logDebug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
