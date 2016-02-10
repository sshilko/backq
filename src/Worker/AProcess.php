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

    /**
     * Set queue this worker is going to use
     *
     * @param $string
     */
    public function setQueueName($string)
    {
        $this->queueName = (string) $string;
    }

    public function run()
    {
        $connected = $this->start();
        $this->debug('started');
        $forks = array();
        if ($connected) {
            try {
                $this->debug('connected');
                $work = $this->work();
                $this->debug('after init work generator');

                /**
                 * Until next job maximum 1 zombie process might be hanging,
                 * we cleanup-up zombies when receiving next job
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\Process)) {
                        $work->send($processed);
                        @error_log('Worker does not support payload of: ' . gettype($message));
                    } else {
                        try {
                            $this->debug('job timeout=' . $message->getTimeout());

                            /**
                             * Enclosure in anonymous function
                             *
                             * ZOMBIE WARNING
                             * @see http://stackoverflow.com/questions/29037880/start-a-background-symfony-process-from-symfony-console
                             *
                             * All the methods that returns results or use results probed by proc_get_status might be wrong
                             * @see https://github.com/symfony/symfony/issues/5759
                             *
                             * @tip use PHP_BINARY for php path
                             */
                            $run = function() use ($message) {
                                $this->debug('launching ' . $message->getCommandline());
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

                            /**
                             * Loop over previous forks and gracefully stop/close them,
                             * doing this before pushing new fork in the pool
                             */
                            if (!empty($forks)) {
                                foreach ($forks as $f) {
                                    try {
                                        /**
                                         * here we PREVENTs ZOMBIES
                                         * isRunning itself closes the process if its ended (not running)
                                         * use `pstree` to look out for zombies
                                         */
                                        if ($f->isRunning()) {
                                            /**
                                             * If its still running, check the timeouts
                                             */
                                            $f->checkTimeout();
                                        }
                                    } catch (ProcessTimedOutException $e) {}
                                }
                            }

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
         * attempt to close the forks nicely,
         * zombies will be killed upon worker death anyway
         */
        foreach ($forks as $f) {
            try {
                /**
                 * isRunning itself closes the process if its ended (not running)
                 */
                if ($f->isRunning()) {
                    /**
                     * stop async process
                     * @see http://symfony.com/doc/current/components/process.html
                     */
                    $f->checkTimeout();
                    $f->stop(1, SIGINT);
                    if ($f->isRunning()) {
                        $f->signal(SIGKILL);
                    }
                }
            } catch (\Exception $e) {}
        }
        $this->finish();
    }
}
