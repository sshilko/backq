<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
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

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessSignaledException;

final class AProcess extends AbstractWorker
{
    protected $queueName = 'process';
    public $workTimeout  = 5;

    public function run()
    {
        $connected = $this->start();
        $this->logDebug('started');
        $forks = array();
        if ($connected) {
            try {
                $this->logDebug('connected');
                $work = $this->work();
                $this->logDebug('after init work generator');

                /**
                 * Until next job maximum 1 zombie process might be hanging,
                 * we cleanup-up zombies when receiving next job
                 */
                foreach ($work as $taskId => $payload) {
                    /**
                     * Whatever happends, always report successful processing
                     */
                    $processed = true;

                    if ($payload) {
                        $this->logDebug('got some payload: ' . $payload);

                        $message = @unserialize($payload);
                        if (!($message instanceof \BackQ\Message\Process)) {
                            $run = false;
                            @error_log('Worker does not support payload of: ' . gettype($message));
                        } else {
                            $run = true;
                        }
                    } else {
                        $this->logDebug('empty loop due to empty payload');

                        $message   = null;
                        $run       = false;
                    }

                    try {

                        if ($run && $message && $deadline = $message->getDeadline()) {
                            if ($deadline < time()) {
                                /**
                                 * Do not run any tasks beyond their deadline
                                 */
                                $run = false;
                            }
                        }

                        if ($run) {
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
                                $this->logDebug('launching ' . $message->getCommandline());
                                $cmd = $message->getCommandline();
                                $timeout = $message->getTimeout() ?? 60;

                                if (!is_array($cmd) && is_string($cmd)) {
                                    /**
                                     * @todo remove - deprecated since symfony 4
                                     * @deprecated
                                     */
                                    $process = Process::fromShellCommandline($cmd,
                                                                             $message->getCwd(),
                                                                             $message->getEnv(),
                                                                             $message->getInput(),
                                                                             /**
                                                                              * timeout does not really work with async (start)
                                                                              */
                                                                             $timeout);
                                } else {
                                    /**
                                     * Using array of arguments is the recommended way to define commands.
                                     * This saves you from any escaping and allows sending signals seamlessly
                                     * (e.g. to stop processes before completion.):
                                     */
                                    $process = new Process($message->getCommandline(),
                                                           $message->getCwd(),
                                                           $message->getEnv(),
                                                           $message->getInput(),
                                                           /**
                                                            * timeout does not really work with async (start)
                                                            */
                                                           $timeout);
                                }

                                /**
                                 * ultimately also disables callbacks
                                 */
                                //$process->disableOutput();

                                /**
                                 * Execute call, starts process in the background
                                 * proc_open($commandline, $descriptors, $this->processPipes->pipes, $this->cwd, $this->env, $this->options);
                                 *
                                 * @throws RuntimeException When process can't be launched
                                 */
                                $process->start();

                                return $process;
                            };
                        }

                        /**
                         * Loop over previous forks and gracefully stop/close them,
                         * doing this before pushing new fork in the pool
                         */
                        if (!empty($forks)) {
                            /** @var Process $f */
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
                                        usleep(200000);
                                    } else {
                                        /**
                                         * Only first call of this function return real value, next calls return -1
                                         */
                                        $ec = $f->getExitCode();
                                        if ($ec > 0) {
                                            trigger_error($f->getCommandLine() . ' [' . $f->getErrorOutput() . '] ' . ' existed with error code ' . $ec, E_USER_WARNING);
                                            $f->clearOutput();
                                            $f->clearErrorOutput();
                                            $ec = null;
                                        }
                                    }

                                } catch (ProcessTimedOutException $e) {

                                } catch (ProcessSignaledException $e) {
                                        /**
                                         * Child process has been terminated by an uncaught signal.
                                         */
                                }
                            }
                        }

                        if ($run) {
                            $forks[] = $run();
                        }
                    } catch (\Exception $e) {
                        /**
                         * Not caching exceptions, just launching processes async
                         */
                        @error_log('Process worker failed to run: ' . $e->getMessage());
                    }

                    $work->send($processed);

                    if (true !== $processed) {
                        /**
                         * Worker not reliable, quitting
                         */
                        throw new \RuntimeException('Worker not reliable, failed to process task: ' . $processed);
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
                    usleep(100000);

                    $f->clearOutput();
                    $f->clearErrorOutput();

                    $f->stop(2, SIGINT);
                    if ($f->isRunning()) {
                        $f->signal(SIGKILL);
                    }
                }
            } catch (\Exception $e) {}
        }
        $this->finish();
    }
}
