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

use BackQ\Adapter\AbstractAdapter;
use function array_keys;
use function array_search;
use function array_values;
use function get_object_vars;
use function serialize;

abstract class AbstractPublisher
{

    protected $bind;

    protected $queueName;

    private $adapter;

    abstract protected function setupAdapter(): AbstractAdapter;

    protected function __construct()
    {
        $this->adapter = $this->setupAdapter();
    }

    /**
     * @param \BackQ\Adapter\AbstractAdapter $adapter
     *
     */
    public static function getInstance(): self
    {
        $class = static::class;

        // @phan-suppress-next-line PhanTypeInstantiateAbstract
        return new $class();
    }

    /**
     * Specify worker queue to push job to
     *
     */
    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * Set queue a publisher will publish to
     *
     * @param $string
     */
    public function setQueueName(string $string): void
    {
        $this->queueName = $string;
    }

    /**
     * Initialize provided adapter
     *
     */
    public function start(): bool
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
    public function ready(): bool
    {
        if ($this->bind) {
            return $this->adapter->ping();
        }

        return false;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     */
    public function hasWorkers(): bool
    {
        return $this->adapter->hasWorkers($this->getQueueName());
    }

    /**
     * Publish new job
     *
     * @param mixed $serializable job payload
     * @param array $params adapter specific params
     *
     */
    public function publish($serializable, $params = []): string|int|false
    {
        if (!$this->bind) {
            return false;
        }

        return $this->adapter->putTask($this->serialize($serializable), $params);
    }

    public function finish(): bool
    {
        if ($this->bind) {
            $this->adapter->disconnect();
            $this->bind = false;

            return true;
        }

        return false;
    }

    protected function serialize($serializable): string
    {
        return serialize($serializable);
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

    public function __wakeup(): void
    {
        $this->adapter = $this->setupAdapter();
    }
}
