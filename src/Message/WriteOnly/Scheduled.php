<?php
namespace BackQ\Message\WriteOnly;

use BackQ\Message\AbstractMessage;

/**
 * Class Scheduled
 * @package BackQ\Message\WriteOnly
 */
class Scheduled extends AbstractMessage
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

    /**
     * @return int
     */
    public function getRecipientsNumber()
    {
        return 1;
    }
}
