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

namespace BackQ\Publisher;

abstract class AbstractPublisher
{
    private $adapter;
    private $bind;

    protected $queueName;

   #protected static $instances = null;

    protected function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

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

    public static function getInstance(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $class = get_called_class();
        return new $class($adapter);

        #$cname = get_class($adapter);
        #if (null === self::$instances || (is_array(self::$instances) && !isset(self::$instances[$cname]))) {
        #    $class = get_called_class();
        #    self::$instances[$cname] = new $class($adapter);
        #}
        #return self::$instances[$cname];
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
        return $this->adapter->putTask(serialize($serializable), $params);
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
