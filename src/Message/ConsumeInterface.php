<?php
namespace BackQ\Message;

/**
 * Interface ConsumeInterface
 */
interface ConsumeInterface
{
    /**
     * Whether a message is currently ready for processing
     * @return bool
     */
    public function isReady(): bool;

    /**
     * Whether the message is still valid for further processing
     * @return bool
     */
    public function isExpired(): bool;
}
