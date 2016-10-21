<?php
/**
 * Copyright (c) 2016, Tripod Technology GmbH <support@tandem.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice,
 *       this list of conditions and the following disclaimer in the documentation
 *       and/or other materials provided with the distribution.
 *
 *    3. Neither the name of Tripod Technology GmbH nor the names of its contributors
 *       may be used to endorse or promote products derived from this software
 *       without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 **/

namespace BackQ\Worker;

use Exception;

abstract class AbstractWorker
{
    private $adapter;
    private $bind;
    private $doDebug;

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
    abstract public function getQueueName();

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

            $lastActive = time();
            if (is_array($job)) {
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
                break;
            }

            if ($this->restartThreshold > 0 && ++$jobsdone > ($this->restartThreshold - 1)) {
                $this->debug('Restart threshold reached, returning job, quitting');
                break;
            }
        }
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
     * Set queue this worker is going to use
     *
     * @param $string
     */
    public function setQueueName($string)
    {
        $this->queueName = (string) $string;
    }

    /**
     * Quit after processing X amount of pushes
     *
     * @param $int
     */
    public function setRestartThreshold($int)
    {
        $this->restartThreshold = (int) $int;
    }

    /**
     * Quit after reaching idle timeout
     *
     * @param $int
     */
    public function setIdleTimeout($int)
    {
        $this->idleTimeout = (int) $int;
    }
}
