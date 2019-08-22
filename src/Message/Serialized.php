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
     * @var string
     */
    protected $message;

    /**
     * @var string
     */
    protected $publisher;

    /**
     * @var array
     */
    protected $publishOptions = [];

    public function __construct(AbstractMessage $message, AbstractPublisher $publisher, array $publishOptions = [])
    {
        $this->message        = serialize($message);
        $this->publisher      = get_class($publisher);
        $this->publishOptions = $publishOptions;
    }

    /**
     * Return publisher to be used for publishing
     *
     * @return AbstractPublisher
     */
    public function getPublisher(): ?AbstractPublisher
    {
        $class = $this->publisher;
        if (@class_exists($class) && @method_exists($class, 'getInstance')) {
            return $class::getInstance();
        }
        return null;
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
    public function getMessage(): AbstractMessage
    {
        return unserialize($this->message);
    }

}