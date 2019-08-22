<?php

namespace BackQ\Message;

/**
 * Class DynamoSQS
 * @package BackQ\Message
 */
class DynamoSQS extends AbstractMessage
{
    /**
     * @var AbstractMessage
     */
    protected $message;

    /**
     * @var string
     */
    protected $nextPublisher;

    public function __construct(string $nextPublisher, AbstractMessage $message)
    {
        $this->message       = $message;
        $this->nextPublisher = $nextPublisher;
    }

    /**
     * Publisher class to be used when the message is ready to be processed
     * @return string
     */
    public function getNextPublisher()
    {
        return $this->nextPublisher;
    }
}
