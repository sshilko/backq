<?php
/**
* BackQ
*
* Copyright (c) 2014, Sergey Shilko (contact@sshilko.com)
*
* @author Sergey Shilko
* @see https://github.com/sshilko/backq
*
**/
namespace BackQ\Worker;

use \RuntimeException;
use \Symfony\Component\Process\Process;

final class AProcess extends AbstractWorker
{
    private $queueName = 'process';

    /**
     * Queue this worker is read from
     *
     * @return string
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    public function run()
    {
        $connected = $this->start();
        $this->debug('started');
        if ($connected) {
            try {
                $this->debug('connected');
                $work = $this->work();
                $this->debug('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\Process)) {
                        $work->send($processed);
                        @error_log('Worker does not support payload of: ' . gettype($message));
                    } else {
                        try {

                            /**
                             * Enclosure in anonymous function
                             */
                            $run = function() use ($message) {
                                $process = new \Symfony\Component\Process\Process\Process($message->getCommandline(),
                                                                                          $message->getCwd(),
                                                                                          $message->getEnv(),
                                                                                          $message->getInput(),
                                                                                          $message->getTimeout(),
                                                                                          $message->getOptions());
                                $process->disableOutput();

                                /**
                                 * Execute call
                                 *
                                 * @throws RuntimeException When process can't be launched
                                 */
                                $process->start();
                            };

                            $run();
                        } catch (\Exception $e) {
                            /**
                             * Not caching exceptions, just launching processes async
                             */
                            @error_log('Process worker failed to run: ' . $e->getMessage());
                        }

                        $work->send((true === $processed));

                        if (true !== $processed) {
                            /**
                             * Worker not reliable, quitting
                             */
                            throw new \RuntimeException('Worker not reliable, failed to process task: ' . $processed);
                        }
                    }
                };
            } catch (\Exception $e) {
                @error_log('Process worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
