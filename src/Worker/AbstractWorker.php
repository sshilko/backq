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

use Exception;

abstract class AbstractWorker
{
    private $adapter;
    private $bind;

    /**
     * Whether syscalls should be delayed
     * @var bool
     */
    protected $manualDelaySignal  = false;
    protected $delaySignalPending = 0;

    protected $queueName;

    /**
     * Quit after processing X amount of pushes
     *
     * @var int
     */
    protected $restartThreshold = 0;

    /**
     * Quit if inactive for specified time (seconds)
     *
     * @var int
     */
    protected $idleTimeout = 0;

    /**
     * Work timeout value
     *
     * @var int
     */
    public $workTimeout = null;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @param int|null $timeout
     */
    public function setWorkTimeout(?int $timeout = null)
    {
        $this->workTimeout = $timeout;
    }

    /**
     * Declare logger
     *
     * @param null|\Psr\Log\LoggerInterface $log
     */
    public function setLogger(?\Psr\Log\LoggerInterface $log)
    {
        $this->logger = $log;
    }

    /**
     * Specify worker queue to pick job from
     *
     * @return string
     */
    public function getQueueName() : string
    {
        return $this->queueName;
    }

    /**
     * Set queue this worker is going to use
     *
     * @param $string
     */
    public function setQueueName(string $string)
    {
        $this->queueName = (string) $string;
    }

    abstract public function run();

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $this->adapter = $adapter;
        $output        = new \Symfony\Component\Console\Output\ConsoleOutput(\Symfony\Component\Console\Output\ConsoleOutput::VERBOSITY_NORMAL);
        $this->setLogger(new \Symfony\Component\Console\Logger\ConsoleLogger($output));
    }

    /**
     * Initialize provided adapter
     *
     * @return bool
     */
    protected function start()
    {
        /**
         * Tell adapter about our desire for work cycle duration, if any
         * Some adapters require it before connecting
         */
        $this->adapter->setWorkTimeout($this->workTimeout);

        if (true === $this->adapter->connect()) {
            if ($this->adapter->bindRead($this->getQueueName())) {
                $this->bind = true;

                /**
                 * Intercept & DELAY SIGNAL EXECUTION-->
                 * @see https://wiki.php.net/rfc/async_signals
                 * @see http://us1.php.net/manual/en/control-structures.declare.php
                 * @see https://github.com/tpunt/PHP7-Reference/blob/master/php71-reference.md
                 */
                $this->delaySignalPending = 0;
                $me = $this;
                if (function_exists('pcntl_signal')) {
                    $signalHandler = function($n) use (&$me) {
                        $me->delaySignalPending = $n;
                    };

                    /**
                     * Termination request
                     */

                    pcntl_signal(SIGTERM, $signalHandler);

                    /**
                     * CTRL+C
                     */

                    pcntl_signal(SIGINT, $signalHandler);

                    /**
                     * shell sends a SIGHUP to all jobs when an interactive login shell exits
                     */

                    pcntl_signal(SIGHUP, $signalHandler);

                    if (function_exists('pcntl_async_signals')) {
                        /**
                         * Asynchronously process triggers w/o manual check
                         */
                        pcntl_async_signals(true);
                    } else {
                        /**
                         * Manually process/check delayed triggers
                         */
                        $this->manualDelaySignal = true;
                    }
                }

                return true;
            }
        }
        return false;
    }

    /**
     * Process data,
     */
    protected function work()
    {
        if (!$this->bind) {
            return;
        }

        $timeout = $this->workTimeout;

        /**
         * Make sure that, if an timeout and idle timeout were set, the timeout is
         * less than the idle timeout
         */
        if ($timeout && $this->idleTimeout > 0) {
            if ($this->idleTimeout <= $timeout) {
                throw new Exception('Time to pick next task cannot be lower than idle timeout');
            }
        }

        $jobsdone   = 0;
        $lastActive = time();
        while (true) {
            /**
             * Manually process pending signals, updates $requestExit value
             * declare(ticks=1) is needed ONLY if we DONT HAVE pcntl_signal_dispatch() call, makes
             * EVERY N TICK's check for signal dispatch,
             * instead we call pcntl_signal_dispatch() manually where we want to check if there was signal
             * @see http://zguide.zeromq.org/php:interrupt
             */
            if ((!$this->manualDelaySignal || pcntl_signal_dispatch()) && $this->isTerminationRequested()) {
                break;
            }

            $this->logDebug('Picking task');
            $job = $this->adapter->pickTask();
            /**
             * @todo $job[2] is optinal array of adapter specific results
             */

            if (is_array($job)) {
                $lastActive = time();

                /**
                 * @see http://php.net/manual/en/generator.send.php
                 */
                $response = (yield $job[0] => $job[1]);
                yield;

                $ack = false;
                if ($response === false) {
                    $this->logDebug('Calling afterWorkFailed, worker reported failure');
                    $ack = $this->adapter->afterWorkFailed($job[0]);
                } else {
                    $this->logDebug('Calling afterWorkSuccess, worker reported success');
                    $ack = $this->adapter->afterWorkSuccess($job[0]);
                }

                if (!$ack) {
                    throw new Exception('Worker failed to acknowledge job result');
                }

            } else {
                /**
                 * Job is a lie
                 */
                if (!$timeout) {
                    throw new Exception('Worker failed to fetch new job');
                } else {
                    /**
                     * Two yield's are not mistake
                     */
                    yield null;
                    yield null;
                }
            }

            /**
             * Break infinite loop when a limit condition is reached
             */
            if ($this->idleTimeout > 0 && (time() - $lastActive) > ($this->idleTimeout - $timeout)) {
                $this->logDebug('Idle timeout reached, returning job, quitting');
                if ($this->onIdleTimeout()) {
                    $this->logDebug('onIdleTimeout true');
                    break;
                } else {
                    $this->logDebug('onIdleTimeout false');
                }
            }

            if ($this->restartThreshold > 0 && ++$jobsdone > ($this->restartThreshold - 1)) {
                $this->logDebug('Restart threshold reached, returning job, quitting');
                if ($this->onRestartThreshold()) {
                    $this->logDebug('onRestartThreshold true');
                    break;
                } else {
                    $this->logDebug('onRestartThreshold false');
                }
            }
        }
    }

    /**
     * @return bool
     */
    protected function onIdleTimeout() {
        return true;
    }

    /**
     * @return bool
     */
    protected function onRestartThreshold() {
        return true;
    }

    /**
     * @return bool
     */
    protected function isTerminationRequested() : bool {
        if ($this->delaySignalPending > 0) {
            if ($this->delaySignalPending == SIGTERM ||
                $this->delaySignalPending == SIGINT  ||
                $this->delaySignalPending == SIGHUP) {
                /**
                 * Received request to stop/terminate process
                 */
                $this->logDebug('termination requested');
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    protected function finish()
    {
        $this->logDebug('finish() called');
        if ($this->bind) {
            $this->logDebug('disconnecting binded adapter');
            $this->adapter->disconnect();
            $this->logDebug('disconnected binded adapter');
            return true;
        }
        return false;
    }

    /**
     * Quit after processing X amount of pushes
     *
     * @param int $int
     */
    public function setRestartThreshold(int $int)
    {
        $this->restartThreshold = (int) $int;
    }

    /**
     * Quit after reaching idle timeout
     *
     * @param int $int
     */
    public function setIdleTimeout(int $int)
    {
        $this->idleTimeout = (int) $int;
    }

    /**
     * @param string $message
     */
    public function logInfo(string $message)
    {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }

    /**
     * @param string $message
     * @deprecated
     */
    public function debug(string $message)
    {
        $this->logDebug($message);
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message)
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }

    /**
     * @param string $message
     */
    public function logError(string $message)
    {
        if ($this->logger) {
            $this->logger->error($message);
        }
    }
}
