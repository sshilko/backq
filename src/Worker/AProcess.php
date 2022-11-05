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

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;
use function assert;
use function error_log;
use function gettype;
use function is_array;
use function is_string;
use function time;
use function trigger_error;
use function unserialize;
use function usleep;
use function var_export;
use const E_USER_WARNING;
use const SIGINT;
use const SIGKILL;

final class AProcess extends AbstractWorker
{

    public $workTimeout  = 5;

    protected $queueName = 'process';

    /**
     * @phpcs:disable SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
     */
    public function run(): void
    {
        $connected = $this->start();
        $this->logDebug('started');
        $forks = [];
        if ($connected) {
            try {
                $this->logDebug('connected');
                $work = $this->work();
                $this->logDebug('after init work generator');

                /**
                 * Until next job maximum 1 zombie process might be hanging,
                 * we cleanup-up zombies when receiving next job
                 * @phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
                 */
                foreach ($work as $_ => $payload) {
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
                        $this->logDebug('empty loop due to empty payload: ' . var_export($payload, true));

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
                            if (!$message->isReady()) {
                                /**
                                 * Message should not be processed yet
                                 */
                                $work->send(false);

                                continue;
                            }

                            if ($message->isExpired()) {
                                $work->send(true);

                                continue;
                            }

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
                            $run = function () use ($message) {
                                $this->logDebug('launching ' . $message->getCommandline());
                                $cmd = $message->getCommandline();
                                /**
                                 * @phpcs:disable SlevomatCodingStandard.ControlStructures.RequireTernaryOperator.TernaryOperatorNotUsed
                                 */
                                $timeout = $message->getTimeout() ?? 60;

                                if (!is_array($cmd) && is_string($cmd)) {
                                    /**
                                     * @todo remove - deprecated since symfony 4
                                     * @deprecated
                                     */
                                    $process = Process::fromShellCommandline(
                                        $cmd,
                                        $message->getCwd(),
                                        $message->getEnv(),
                                        $message->getInput(),
                                        /**
                                                                              * timeout does not really work with async (start)
                                                                              */
                                        $timeout
                                    );
                                } else {
                                    /**
                                     * Using array of arguments is the recommended way to define commands.
                                     * This saves you from any escaping and allows sending signals seamlessly
                                     * (e.g. to stop processes before completion.):
                                     */
                                    $process = new Process(
                                        $message->getCommandline(),
                                        $message->getCwd(),
                                        $message->getEnv(),
                                        $message->getInput(),
                                        /**
                                                            * timeout does not really work with async (start)
                                                            */
                                        $timeout
                                    );
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
                            foreach ($forks as $f) {
                                assert($f instanceof Process);
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
                                            trigger_error(
                                                $f->getCommandLine() . ' [' . $f->getErrorOutput() . '] ' . ' existed with error code ' . $ec,
                                                E_USER_WARNING
                                            );
                                            $f->clearOutput();
                                            $f->clearErrorOutput();
                                            $ec = null;
                                        }
                                    }
                                } catch (ProcessTimedOutException $e) {
                                    @error_log('Process worker caught ProcessTimedOutException: ' . $e->getMessage());
                                } catch (ProcessSignaledException $e) {
                                    @error_log('Process worker caught ProcessSignaledException: ' . $e->getMessage());
                                    /**
                                     * Child process has been terminated by an uncaught signal.
                                     */
                                }
                            }
                        }

                        if ($run) {
                            $forks[] = $run();
                        }
                    } catch (Throwable $e) {
                        /**
                         * Not caching exceptions, just launching processes async
                         */
                        @error_log('Process worker failed to run: ' . $e->getMessage());
                    }

                    $this->logDebug('reporting work as processed: ' . var_export($processed, true));
                    $work->send($processed);

                    if (true !== $processed) {
                        /**
                         * Worker not reliable, quitting
                         */
                        throw new RuntimeException('Worker not reliable, failed to process task: ' . $processed);
                    }
                }
            } catch (Throwable $e) {
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
            } catch (Throwable $e) {
                @error_log('Process worker failed to stop forked child: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
