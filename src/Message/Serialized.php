<?php

namespace BackQ\Message;

use BackQ\Publisher\AbstractPublisher;

/**
 * Class DynamoSQSSlow
 * @package BackQ\Message
 */
class Serialized extends AbstractMessage
{
    /**
     * @var ?AbstractMessage
     */
    protected $message;

    /**
     * @var ?AbstractPublisher
     */
    protected $publisher;

    /**
     * @var array
     */
    protected $publishOptions = [];

    public function __construct(AbstractMessage $message, AbstractPublisher $publisher, array $publishOptions = [])
    {
        $this->message        = $message;
        $this->publisher      = $publisher;
        $this->publishOptions = $publishOptions;
    }

    /**
     * Return publisher to be used for publishing
     *
     * @return AbstractPublisher
     */
    public function getPublisher(): ?AbstractPublisher
    {
        return $this->publisher;
    }

    /**
     * Return options to be used when publisher publishes
     *
     * @return array
     */
    public function getPublishOptions(): array
    {
        return $this->publishOptions;
    }

    /**
     * Return message to be (re) published
     *
     * @return AbstractMessage
     */
    public function getMessage(): ?AbstractMessage
    {
        return $this->message;
    }

}