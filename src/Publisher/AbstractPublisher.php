<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Publisher;

abstract class AbstractPublisher
{
    private $adapter;

    protected $bind;
    protected $queueName;

    protected function __construct()
    {
        $this->adapter = $this->setupAdapter();
    }

    public function __sleep()
    {
        if ($this->adapter) {
            $this->adapter->disconnect();
        }

        $vars = array_keys(get_object_vars($this));
        unset($vars[array_search('adapter', $vars, true)]);
        return array_values($vars);
    }

    public function __wakeup()
    {
        $this->adapter = $this->setupAdapter();
    }

    abstract protected function setupAdapter(): \Backq\Adapter\AbstractAdapter;

    /**
     * Specify worker queue to push job to
     *
     * @return string
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    /**
     * Set queue a publisher will publish to
     *
     * @param $string
     */
    public function setQueueName(string $string)
    {
        $this->queueName = (string) $string;
    }

    /**
     * @param \BackQ\Adapter\AbstractAdapter $adapter
     *
     * @return AbstractPublisher
     */
    public static function getInstance()
    {
        $class = get_called_class();
        return new $class();
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
     * @return string|false
     */
    public function publish($serializable, $params = array())
    {
        if (!$this->bind) {
            return false;
        }
        return $this->adapter->putTask($this->serialize($serializable), $params);
    }

    protected function serialize($serializable): string
    {
        return serialize($serializable);
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
