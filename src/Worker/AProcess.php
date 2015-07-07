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
            $forks = array();
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
                             *
                             * ZOMBIE WARNING
                             * @see http://stackoverflow.com/questions/29037880/start-a-background-symfony-process-from-symfony-console
                             *
                             * All the methods that returns results or use results probed by proc_get_status might be wrong
                             * @see https://github.com/symfony/symfony/issues/5759
                             * the only solution found is to prepend the command with [exec]
                             *
                             * @tip use PHP_BINARY for php path
                             */
                            $run = function() use ($message) {
                                $process = new \Symfony\Component\Process\Process($message->getCommandline(),
                                                                                  $message->getCwd(),
                                                                                  $message->getEnv(),
                                                                                  $message->getInput(),
                                                                                  /**
                                                                                   * timeout does not really work with async (start)
                                                                                   */
                                                                                  $message->getTimeout(),
                                                                                  $message->getOptions());
                                /**
                                 * no win support here
                                 */
                                $process->setEnhanceWindowsCompatibility(false);

                                /**
                                 * ultimately also disables callbacks
                                 */
                                $process->disableOutput();

                                /**
                                 * Execute call
                                 * proc_open($commandline, $descriptors, $this->processPipes->pipes, $this->cwd, $this->env, $this->options);
                                 *
                                 * @throws RuntimeException When process can't be launched
                                 */
                                $process->start();
                                return $process;
                            };

                            $forks[] = $run();
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
        /**
         * Keep the references to forks until the end of execution,
         * attempt to prevent zombie processes
         */
        foreach ($forks as $f) {
            try {
                /**
                 * stop async process
                 * @see http://symfony.com/doc/current/components/process.html
                 */
                $f->stop(2, SIGINT);
                if ($f->isRunning()) {
                    $f->signal(SIGKILL);
                }
            } catch (\Exception $e) {}
        }
        $this->finish();
    }
}
