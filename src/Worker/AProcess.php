<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2017 Sergei Shilko <contact@sshilko.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 **/

namespace BackQ\Worker;

use \RuntimeException;
use \Symfony\Component\Process\Process;

final class AProcess extends AbstractWorker
{
    protected $queueName = 'process';
    public $workTimeout  = 4;

    public function run()
    {
        $connected = $this->start();
        $this->debug('started');
        $forks = array();
        if ($connected) {
            try {
                $this->debug('connected');
                $work = $this->work($this->workTimeout);
                $this->debug('after init work generator');

                /**
                 * Until next job maximum 1 zombie process might be hanging,
                 * we cleanup-up zombies when receiving next job
                 */
                foreach ($work as $taskId => $payload) {
                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }

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
