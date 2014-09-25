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
    private $adapter;
    private $bind;

    protected static $instances = null;

    protected function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Specify worker queue to push job to
     */
    abstract public function getQueueName();

    public static function getInstance(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $cname = get_class($adapter);

        if (null === self::$instances || (is_array(self::$instances) && !isset(self::$instances[$cname]))) {
            $class = get_called_class();
            self::$instances[$cname] = new $class($adapter);
        }
        return self::$instances[$cname];
    }

    /**
     * Initialize provided adapter
     *
     * @return bool
     */
    public function start()
    {
        if (true === $this->bind) {
            return true;
        }
        if (true === $this->adapter->connect()) {
            if ($this->adapter->bindWrite($this->getQueueName())) {
                $this->bind = true;
                return true;
            }
        }
        return false;
    }

    /**
     * Check if connection is alive and ready to do the job
     */
    public function ready()
    {
        if ($this->bind) {
            return $this->adapter->ping();
        }
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * @return null|int
     */
    public function hasWorkers()
    {
        return $this->adapter->hasWorkers($this->getQueueName());
    }

    /**
     * Publish new job
     *
     * @param mixed $serializable job payload
     * @param array $params adapter specific params
     *
     * @return integer|bool
     */
    public function publish($serializable, $params = array())
    {
        if (!$this->bind) {
            return false;
        }
        return $this->adapter->putTask(serialize($serializable));
    }

    public function finish()
    {
        if ($this->bind) {
            $this->adapter->disconnect();
            $this->bind = false;
            return true;
        }
        return false;
    }

}
