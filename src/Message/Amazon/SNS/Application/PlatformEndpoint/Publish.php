<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Message\Amazon\SNS\Application\PlatformEndpoint;

use function json_encode;

class Publish implements PublishMessageInterface
{

    protected $attributes;

    /**
     * Amazon Resource name that uniquely identifies a Resource on AWS that we'll
     * publish to, in this case it's an endpoint
     */
    protected string $targetArn;

    /**
     * Message payload
     * @see http://docs.aws.amazon.com/sns/latest/dg/mobile-push-send-custommessage.html
     *
     * @var array
     */
    protected array $message;

    protected $messageStructure;

    /**
     * Message payload
     *
     * @param array $message
     */
    public function setMessage(array $message): void
    {
        $this->message = $message;
    }

    /**
     * Takes the data and properly assigns it to a json encoded array to wrap
     * a subset of Gcm format into a customContent key
     *
     */
    public function getMessage(): string
    {
        return json_encode($this->message);
    }

    /**
     * Returns the Amazon Resource Name for the endpoint a message should be published to
     *
     */
    public function getTargetArn(): string
    {
        return $this->targetArn;
    }

    /**
     * Sets up the Resource Identifier for the endpoint that a message will be published to
     *
     * @param string $targetArn
     */
    public function setTargetArn(string $targetArn): void
    {
        $this->targetArn = $targetArn;
    }

    /**
     * Gets specific attributes to complete a Publish operation to an endpoint
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Sets attributes specific to different platforms in order to publish a message
     *
     * @param array $attrs
     */
    public function setAttributes(array $attrs): void
    {
        $this->attributes = $attrs;
    }

    public function getMessageStructure(): string
    {
        return $this->messageStructure;
    }

    public function setMessageStructure(string $structure): void
    {
        $this->messageStructure = $structure;
    }
}
