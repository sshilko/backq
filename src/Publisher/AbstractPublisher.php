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
use BadMethodCallException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function array_keys;
use function array_search;
use function array_values;
use function get_object_vars;
use function is_array;
use function serialize;
use function trigger_error;
use const E_USER_DEPRECATED;

abstract class AbstractPublisher
{

    protected bool $bind = false;

    protected string $queueName;

    /**
     * Not serialized, an adapter holds a live connection
     * A publisher that travels through a queue has to restore it in __wakeup()
     */
    protected ?AbstractAdapter $adapter = null;

    public function __construct(AbstractAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @deprecated use the constructor instead: new Publisher($adapter)
     */
    public static function getInstance(): self
    {
        $deprecation = static::class . '::getInstance() is deprecated, use the constructor instead: new '
            . static::class . '($adapter)';

        trigger_error($deprecation, E_USER_DEPRECATED);

        throw new BadMethodCallException($deprecation);
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
        if (null === $this->adapter) {
            return false;
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
        if ($this->bind && null !== $this->adapter) {
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
        return null !== $this->adapter && $this->adapter->hasWorkers($this->getQueueName());
    }

    /**
     * Publish new job
     *
     * Any further argument is forwarded to AbstractAdapter::putTask() as a named argument, so
     * the caller names the parameter the adapter declares:
     *
     *     $publisher->publish($message, readyWait: 5);
     *     $publisher->publish($message, readyWait: 5, jobTtr: 30);
     *     $publisher->publish($message, jobId: 'a-1');
     *
     * @param mixed $serializable job payload
     * @param mixed ...$params    named arguments for the adapter
     *
     * @return string|Throwable|null the job id, null when the adapter reports no ids,
     *                              the failure otherwise
     *
     * @throws InvalidArgumentException when an options array is passed instead of named arguments
     */
    public function publish(mixed $serializable, mixed ...$params): null|string|Throwable
    {
        foreach ($params as $param) {
            if (is_array($param)) {
                throw new InvalidArgumentException(
                    static::class . '::publish() takes named arguments, not an options array: '
                    . 'publish($message, readyWait: 5)'
                );
            }
        }

        if (!$this->bind) {
            return new RuntimeException(static::class . '::publish() before start(): call start() first');
        }

        if (null === $this->adapter) {
            return $this->noAdapterError();
        }

        return $this->adapter->putTask($this->serialize($serializable), ...$params);
    }

    public function finish(): bool
    {
        if ($this->bind && null !== $this->adapter) {
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

    /**
     * __sleep() drops the adapter because it holds a live connection, so a publisher that
     * travelled through a queue has none until __wakeup() rebuilds it
     */
    private function noAdapterError(): RuntimeException
    {
        return new RuntimeException(
            static::class . ': no adapter. The publisher was serialized, so rebuild the adapter in __wakeup()'
        );
    }

    public function __sleep()
    {
        if (isset($this->adapter)) {
            $this->adapter->disconnect();
        }

        $vars = array_keys(get_object_vars($this));
        unset($vars[array_search('adapter', $vars, true)]);

        return array_values($vars);
    }
}
