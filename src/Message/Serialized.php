<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message;

use BackQ\Publisher\AbstractPublisher;

/**
 * @phpcs:disable
 */
class Serialized extends AbstractMessage
{

    protected ?AbstractMessage $message = null;

    protected ?AbstractPublisher $publisher = null;

    /**
     * Named arguments the worker replays on AbstractPublisher::publish(), keyed by parameter name
     *
     * It has to stay an array, because the payload is serialized into the queue:
     *
     *     new Serialized($message, $publisher, ['readyWait' => 5, 'jobTtr' => 30]);
     *
     * @var array<string, mixed>
     */
    protected array $publishOptions = [];

    /**
     * @param array<string, mixed> $publishOptions named arguments for publish()
     */
    public function __construct(
        AbstractMessage $message,
        AbstractPublisher $publisher,
        array $publishOptions = []
    ) {
        $this->message        = $message;
        $this->publisher      = $publisher;
        $this->publishOptions = $publishOptions;
    }

    /**
     * Return publisher to be used for publishing
     *
     */
    public function getPublisher(): ?AbstractPublisher
    {
        if ($this->publisher instanceof AbstractPublisher) {
            /**
             * If publisher is unknown, it will be unserialized as
             * __PHP_Incomplete_Class_Name
             */
            return $this->publisher;
        }

        return null;
    }

    /**
     * Return the named arguments the worker replays on publish()
     *
     * @return array<string, mixed>
     */
    public function getPublishOptions(): array
    {
        return $this->publishOptions;
    }

    /**
     * Return message to be (re) published
     *
     */
    public function getMessage(): ?AbstractMessage
    {
        return $this->message;
    }
}
