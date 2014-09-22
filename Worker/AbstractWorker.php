<?php
namespace BackQ\Worker;
use Exception;

abstract class AbstractWorker
{
    private $_adapter;
    private $_bind;
    private $_doDebug;

    /**
     * Specify worker queue to pick job from
     */
    abstract public function getQueueName();

    abstract public function run();

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter) {
        $this->_adapter = $adapter;
    }

    /**
     * Initialize provided adapter
     *
     * @return bool
     */
    protected function start() {
        if (true === $this->_adapter->connect()) {
            if ($this->_adapter->bindRead($this->getQueueName())) {
                $this->_bind = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Process data, 
     */
    protected function work() {
        if (!$this->_bind) {
            return;
        }

        while (true) {
            $job = $this->_adapter->pickTask();
            if (!is_array($job)) {
                throw new Exception('Worker failed to fetch new job');
            }

            /**
             * @see http://php.net/manual/en/generator.send.php
             */
            $result = (yield $job[0] => $job[1]);
            yield;

            $ack = FALSE;
            if ($response === FALSE) {
                $ack = $this->_adapter->afterWorkFailed($job[0]);
            } else {
                $ack = $this->_adapter->afterWorkSuccess($job[0]);
            }

            if (!$ack) {
                throw new Exception('Worker failed to acknowledge job result');
            }
        }
    }

    protected function finish() {
        if ($this->_bind) {
            $this->_adapter->disconnect();
            return true;
        }
        return false;
    }

    public function toggleDebug($flag) {
        $this->_doDebug = $flag;
    }

    /**
     * Process debug logging if needed
     */
    protected function _debug($log) {
        if ($this->_doDebug) {
            echo "\n" . $log;
        }
    }
}
