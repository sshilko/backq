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

use Exception;

abstract class AbstractWorker
{
    private $adapter;
    private $bind;
    private $doDebug;

    /**
     * Specify worker queue to pick job from
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
    protected function work()
    {
        if (!$this->bind) {
            return;
        }

        while (true) {
            $job = $this->adapter->pickTask();
            if (!is_array($job)) {
                throw new Exception('Worker failed to fetch new job');
            }

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
            echo "\n" . $log;
        }
    }
}
