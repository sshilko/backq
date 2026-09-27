<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker;

use BackQ\Adapter\AbstractAdapter;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use function function_exists;
use function is_array;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function time;
use function var_export;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;

abstract class AbstractWorker
{
    /**
     * Seconds a work cycle may take unless the caller says otherwise
     */
    public const int DEFAULT_WORK_TIMEOUT = 60;

    /**
     * Work timeout value, in seconds. Handed to the adapter on start().
     */
    public ?int $workTimeout = self::DEFAULT_WORK_TIMEOUT;

    /**
     * Whether syscalls should be delayed
     */
    protected bool $manualDelaySignal  = false;

    protected $delaySignalPending = 0;

    protected string $queueName;

    /**
     * Quit after processing X amount of pushes
     *
     */
    protected int $restartThreshold = 0;

    /**
     * Quit if inactive for specified time (seconds)
     *
     */
    protected int $idleTimeout = 0;

    protected ?LoggerInterface $logger = null;

    private bool $bind = false;

    abstract public function run(): void;

    /**
     * @param int|null $workTimeout seconds a work cycle may take, DEFAULT_WORK_TIMEOUT unless set
     */
    public function __construct(private AbstractAdapter $adapter, ?int $workTimeout = self::DEFAULT_WORK_TIMEOUT)
    {
        $this->workTimeout = $workTimeout;

        $output        = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL);
        $this->setLogger(new ConsoleLogger($output));
    }

    /**
     * @deprecated pass the timeout to the constructor: new Serialized($adapter, workTimeout: 5)
     *
     * Still functional, so existing workers keep their pick cycle. Nothing announces the
     * deprecation at runtime: this library reports through PSR-3, and a PHP notice is not
     * a channel it uses.
     *
     * @param int|null $timeout
     */
    public function setWorkTimeout(?int $timeout = null): void
    {
        $this->workTimeout = $timeout;
    }

    /**
     * Declare logger
     *
     * @param LoggerInterface|null $log
     */
    public function setLogger(?LoggerInterface $log): void
    {
        $this->logger = $log;
    }

    /**
     * Specify worker queue to pick job from
     *
     */
    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * Set queue this worker is going to use
     *
     * @param $string
     */
    public function setQueueName(string $string): void
    {
        $this->queueName = $string;
    }

    /**
     * Quit after processing X amount of pushes
     *
     * @param int $int
     */
    public function setRestartThreshold(int $int): void
    {
        $this->restartThreshold = $int;
    }

    /**
     * Quit after reaching idle timeout
     *
     * @param int $int
     */
    public function setIdleTimeout(int $int): void
    {
        $this->idleTimeout = $int;
    }

    /**
     * @param string $message
     */
    public function logInfo(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->info($message);
        }
    }

    /**
     * @param string $message
     */
    public function logDebug(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->debug($message);
        }
    }

    /**
     * @param string               $message
     * @param array<string, mixed> $context
     */
    public function logError(string $message, array $context = []): void
    {
        if (isset($this->logger)) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Initialize provided adapter
     *
     */
    protected function start(): bool
    {
        /**
         * Tell adapter about our desire for work cycle duration, if any
         * Some adapters require it before connecting
         */
        $timeout = $this->effectiveWorkTimeout();
        if ($timeout !== $this->workTimeout) {
            $this->logDebug('Work timeout ' . var_export($this->workTimeout, true)
                . ' lowered to ' . var_export($timeout, true)
                . ' to end a work cycle before the idle timeout of ' . $this->idleTimeout . 's');
        }
        $this->adapter->setWorkTimeout($timeout);

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
                    $signalHandler = static function ($n) use (&$me): void {
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
     *
     *
     * @psalm-return \Generator<int|string|null, string|null, mixed, null>
     */
    protected function work(): \Generator
    {
        if (!$this->bind) {
            return;
        }

        $timeout = $this->effectiveWorkTimeout();

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
                /**
                 * @var array{0: string|int|null, 1: string} $job
                 */
                $response = (yield $job[0] => $job[1]);
                yield;

                /**
                 * The acknowledge methods take the job id as a string. A backend that
                 * reports one as an int (beanstalkd, a mysqli row) keeps doing so, so the
                 * cast belongs here, where the two are invoked.
                 */
                $workId = null === $job[0] ? null : (string) $job[0];

                if (false === $response) {
                    $this->logDebug('Calling afterWorkFailed, worker reported failure');
                    $ack = $this->adapter->afterWorkFailed($workId);
                } else {
                    $this->logDebug('Calling afterWorkSuccess, worker reported success');
                    $ack = $this->adapter->afterWorkSuccess($workId);
                }

                if (!$ack) {
                    throw new Exception('Worker failed to acknowledge job result');
                }
            } else {
                /**
                 * No job was available this cycle (heartbeat / idle poll).
                 * Not an error: adapters surface real connection failures by throwing.
                 */
                yield null;
                yield null;
            }

            /**
             * Break infinite loop when a limit condition is reached
             */
            if ($this->idleTimeout > 0 && (time() - $lastActive) > $this->idleTimeout - $timeout) {
                $this->logDebug('Idle timeout reached, returning job, quitting');
                if ($this->onIdleTimeout()) {
                    $this->logDebug('onIdleTimeout true');

                    break;
                }

                $this->logDebug('onIdleTimeout false');
            }

            if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold - 1) {
                $this->logDebug('Restart threshold reached, returning job, quitting');
                if ($this->onRestartThreshold()) {
                    $this->logDebug('onRestartThreshold true');

                    break;
                }

                $this->logDebug('onRestartThreshold false');
            }
        }
    }

    /**
     */
    protected function onIdleTimeout(): bool
    {
        return true;
    }

    /**
     */
    protected function onRestartThreshold(): bool
    {
        return true;
    }

    /**
     */
    protected function isTerminationRequested(): bool
    {
        if ($this->delaySignalPending > 0) {
            if (SIGTERM === $this->delaySignalPending ||
                SIGINT === $this->delaySignalPending ||
                SIGHUP === $this->delaySignalPending
            ) {
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
     */
    protected function finish(): bool
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
     * The pick timeout this worker really works with. A work cycle may not outlast the
     * idle timeout, the loop has to reach its idle check while the deadline is still ahead
     * of it, so a timeout that reaches the idle timeout is lowered one second below it.
     * The adapter and the loop both read the result, so they cannot disagree.
     *
     * @return int|null null leaves the adapter to poll without blocking
     */
    private function effectiveWorkTimeout(): ?int
    {
        $timeout = $this->workTimeout;

        if (null === $timeout || $timeout <= 0 || $this->idleTimeout <= 0) {
            return $timeout;
        }

        if ($this->idleTimeout <= $timeout) {
            return $this->idleTimeout - 1;
        }

        return $timeout;
    }
}
