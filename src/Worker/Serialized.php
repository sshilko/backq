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

use BackQ\Message\AbstractMessage;
use BackQ\Publisher\AbstractPublisher;

class Serialized extends AbstractWorker
{
    /**
     * @var int
     */
    public $workTimeout = 5;

    public function run()
    {
        $connected = $this->start();
        $this->logInfo('started');

        if ($connected) {
            try {
                $this->logInfo('before init work generator');

                $work = $this->work();
                $this->logInfo('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->logInfo(time() . ' got some work: ' . ($payload ? 'yes' : 'no'));

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        $work->send(true);
                        continue;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\Serialized)) {
                        $work->send(true);
                        $this->logError('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    $originalPublisher = $message->getPublisher();
                    $originalMessage   = $message->getMessage();
                    $originalPubOpts   = $message->getPublishOptions();

                    if ($originalPublisher && $originalMessage) {
                        if (!$message->isReady()) {
                            /**
                             * Message should not be processed now
                             */
                            $work->send(false);
                            continue;
                        }

                        if ($message->isExpired()) {
                            $work->send(true);
                            $this->logDebug('Discarding serialized message as already expired');
                            continue;
                        }

                        $processed = false;
                        try {
                            if ($this->dispatchOriginalMessage($originalPublisher,
                                                               $originalMessage,
                                                               $originalPubOpts)) {
                                $processed = true;
                            }
                        } catch (\Exception $ex) {
                            $this->logError($ex->getMessage());
                        }
                    } else {
                        if (!$originalMessage) {
                            $this->logError('Missing original message');
                        }
                        if (!$originalPublisher) {
                            $this->logError('Missing original publisher');
                        }
                    }

                    $work->send($processed);
                }
            } catch (\Exception $e) {
                $this->logError($e->getMessage());
            }
        }
        $this->finish();
    }

    /**
     * @param AbstractPublisher $publisher
     * @param AbstractMessage $message
     * @param array $publishOptions
     * @return string|null
     */
    private function dispatchOriginalMessage(AbstractPublisher $publisher,
                                             AbstractMessage $message,
                                             array $publishOptions = []): ?string
    {
        if ($publisher->start()) {
            return (string) $publisher->publish($message, $publishOptions);
        }
        return null;
    }
}
