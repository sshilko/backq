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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use function date;
use function gettype;
use function is_string;
use function json_encode;
use function unserialize;
use const JSON_THROW_ON_ERROR;

final class Guzzle extends AbstractWorker
{

    public ?int $workTimeout = 4;

    protected $queueName = 'guzzle';
    
    /**
     * @phpcs:disable SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
     */
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

                    if (!($message instanceof \BackQ\Message\Guzzle)) {
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

                    try {
                        $me = $this;

                        $request = $message->getRequest();
                        $promise = $client->sendAsync($request)->then(
                            static function (ResponseInterface $fulfilledResponse) use ($me): void {
                                $me->logDebug('Request sent, got response ' . $fulfilledResponse->getStatusCode() .
                                         ' ' . json_encode(
                                             (string) $fulfilledResponse->getBody(),
                                             JSON_THROW_ON_ERROR
                                         ));
                            },
                            static function (Throwable $rejectedResponse) use ($me, &$processed): void {
                                $me->logDebug('Request sent, FAILED with ' . $rejectedResponse->getMessage());
                                if (!(
                                        $rejectedResponse instanceof RequestException
                                        && null !== $rejectedResponse->getResponse()
                                    )
                                ) {
                                    $processed = false;
                                }
                            }
                        );

                        $promise->wait();
                    } catch (Throwable $e) {
                        $processed = false;
                    } finally {
                        /**
                         * If using Beanstalk and not returned success after TTR time,
                         * the job considered failed and is put back into pool of "ready" jobs
                         */
                        $work->send((true === $processed));
                    }
                }
            } catch (Throwable $e) {
                $this->logDebug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
