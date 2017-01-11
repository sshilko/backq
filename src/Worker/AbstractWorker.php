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

use Exception;

abstract class AbstractWorker
{
    private $adapter;
    private $bind;
    private $doDebug;

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
    }

    /**
     * Initialize provided adapter
     *
     * @return bool
     */
    protected function start()
    {
        if (true === $this->adapter->connect()) {
            if ($this->adapter->bindRead($this->getQueueName())) {
                $this->bind = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Process data,
     */
    protected function work($timeout = null)
    {
        if (!$this->bind) {
            return;
        }

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
            $job = $this->adapter->pickTask($timeout);

            if (is_array($job)) {
                $lastActive = time();

                /**
                 * @see http://php.net/manual/en/generator.send.php
                 */
                $response = (yield $job[0] => $job[1]);
                yield;

                $ack = false;
                if ($response === false) {
                    $ack = $this->adapter->afterWorkFailed($job[0]);
                } else {
                    $ack = $this->adapter->afterWorkSuccess($job[0]);
                }

                if (!$ack) {
                    throw new Exception('Worker failed to acknowledge job result');
                }

            } else {
                if (!$timeout) {
                    throw new Exception('Worker failed to fetch new job');
                } else {
                    yield;
                }
            }

            /**
             * Break infinite loop when a limit condition is reached
             */
            if ($this->idleTimeout > 0 && (time() - $lastActive) > ($this->idleTimeout - $timeout)) {
                $this->debug('Idle timeout reached, returning job, quitting');
                $this->onIdleTimeout();
                break;
            }

            if ($this->restartThreshold > 0 && ++$jobsdone > ($this->restartThreshold - 1)) {
                $this->debug('Restart threshold reached, returning job, quitting');
                $this->onRestartThreshold();
                break;
            }
        }
    }

    protected function onIdleTimeout() {

    }

    protected function onRestartThreshold() {

    }

    protected function finish()
    {
        if ($this->bind) {
            $this->adapter->disconnect();
            return true;
        }
        return false;
    }

    public function toggleDebug($flag)
    {
        $this->doDebug = $flag;
    }

    /**
     * Process debug logging if needed
     */
    protected function debug($log)
    {
        if ($this->doDebug) {
            echo $log . "\n";
        }
    }

    /**
     * Quit after processing X amount of pushes
     *
     * @param $int
     */
    public function setRestartThreshold(int $int)
    {
        $this->restartThreshold = (int) $int;
    }

    /**
     * Quit after reaching idle timeout
     *
     * @param $int
     */
    public function setIdleTimeout(int $int)
    {
        $this->idleTimeout = (int) $int;
    }
}
