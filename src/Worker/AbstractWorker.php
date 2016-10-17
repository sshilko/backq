<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Tripod Technology GmbH
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

        $jobsdone   = 0;
        $break      = false;
        $lastActive = time();
        while (true) {
            $job = $this->adapter->pickTask($timeout);

            /**
             * @see http://php.net/manual/en/generator.send.php
             */
            if ($this->idleTimeout > 0 && (time() - $lastActive) > $this->idleTimeout-$timeout) {
                $this->debug('Idle timeout reached, returning job, quitting');
                $break = true;
            }

            if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold - 1) {
                $this->debug('Restart threshold reached, returning job, quitting');
                $break = true;
            }

            $lastActive = time();
            if (is_array($job)) {
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

                /**
                 * Break infinite loop when a limit condition was reached
                 */
                if ($break) {
                    break;
                }
            } else {
                if (!$timeout) {
                    throw new Exception('Worker failed to fetch new job');
                } else {
                    yield;
                }
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
