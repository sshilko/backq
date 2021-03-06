<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker;

use BackQ\Worker\Closure\RecoverableException;

class Closure extends AbstractWorker
{
    /**
     * @var string
     */
    protected $queueName = 'closure';

    /**
     * @var int
     */
    public $workTimeout = 5;

    public function run()
    {
        $connected = $this->start();
        $this->logDebug('started');

        if ($connected) {
            try {
                $this->logInfo('before init work generator');

                $work = $this->work();
                $this->logInfo('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->logInfo(time() . ' got some work: ' . ($payload ? 'yes' : 'no'));
                    if (!$payload) {
                        $work->send(true);
                        continue;
                    }

                    $message = @unserialize($payload);
                    if (!($message instanceof \BackQ\Message\Closure)) {
                        $work->send(true);
                        $this->logError('Closure worker does not support payload of: ' . gettype($message));
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
                        $message->execute();
                    } catch (RecoverableException $e) {
                        /**
                         * The closure execution failed but can be retried
                         */
                        $this->logError('Failed executing closure ' . $e->getMessage());
                        $work->send(false);
                        continue;
                    } catch (\Throwable $e) {
                        $this->logError('Error executing closure ' . $e->getMessage());
                    }
                    $work->send(true);
                }
            } catch (\Exception $e) {
                $this->logError($e->getMessage());
            }
        }
        $this->finish();
    }
}
