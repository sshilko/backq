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
namespace BackQ\Publisher;

abstract class AbstractPublisher
{
    private $_adapter;
    private $_bind;

    protected static $_instances = null;

    protected function __construct(\BackQ\Adapter\AbstractAdapter $adapter) {
        $this->_adapter = $adapter;
    }

    /**
     * Specify worker queue to push job to
     */
    abstract public function getQueueName();

    public static function getInstance(\BackQ\Adapter\AbstractAdapter $adapter) {
        $cname = get_class($adapter);

        if (null === self::$_instances || (is_array(self::$_instances) && !isset(self::$_instances[$cname]))) {
            $class = get_called_class();
            self::$_instances[$cname] = new $class($adapter);
        }
        return self::$_instances[$cname];
    }

    /**
     * Initialize provided adapter
     *
     * @return bool
     */
    public function start() {
        if (true === $this->_bind) {
            return true;
        }
        if (true === $this->_adapter->connect()) {
            if ($this->_adapter->bindWrite($this->getQueueName())) {
                $this->_bind = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Check if connection is alive and ready to do the job
     */
    public function ready() {
        if ($this->_bind) {
            return $this->_adapter->ping();
        }
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * @return null|int
     */
    public function hasWorkers() {
        return $this->_adapter->hasWorkers($this->getQueueName());
    }

    /**
     * Publish new job
     *
     * @param mixed $serializable job payload
     * @param array $params adapter specific params
     * 
     * @return integer|bool
     */
    public function publish($serializable, $params = array()) {
        if (!$this->_bind) {
            return false;
        }
        return $this->_adapter->putTask(serialize($serializable));
    }

    public function finish() {
        if ($this->_bind) {
            $this->_adapter->disconnect();
            $this->_bind = false;
            return true;
        }
        return false;
    }

}
