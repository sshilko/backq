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

use Override;
use function json_encode;

class Publish implements PublishMessageInterface
{

    protected array $attributes = [];

    /**
     * Amazon Resource name that uniquely identifies a Resource on AWS that we'll
     * publish to, in this case it's an endpoint
     */
    protected string $targetArn = '';

    /**
     * Message payload
     * @see http://docs.aws.amazon.com/sns/latest/dg/mobile-push-send-custommessage.html
     *
     */
    protected array $message = [];

    protected string $messageStructure = '';

    /**
     * Message payload
     *
     * @param array $message
     */
    #[Override]
    public function setMessage(array $message): void
    {
        $this->message = $message;
    }

    /**
     * Takes the data and properly assigns it to a json encoded array to wrap
     * a subset of Gcm format into a customContent key
     *
     */
    #[Override]
    public function getMessage(): string|false
    {
        return json_encode($this->message);
    }

    /**
     * Returns the Amazon Resource Name for the endpoint a message should be published to
     *
     */
    #[Override]
    public function getTargetArn(): string
    {
        return $this->targetArn;
    }

    /**
     * Sets up the Resource Identifier for the endpoint that a message will be published to
     *
     * @param string $targetArn
     */
    #[Override]
    public function setTargetArn(string $targetArn): void
    {
        $this->targetArn = $targetArn;
    }

    /**
     * Gets specific attributes to complete a Publish operation to an endpoint
     *
     */
    #[Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Sets attributes specific to different platforms in order to publish a message
     *
     * @param array $attrs
     */
    #[Override]
    public function setAttributes(array $attrs): void
    {
        $this->attributes = $attrs;
    }

    #[Override]
    public function getMessageStructure(): string
    {
        return $this->messageStructure;
    }

    #[Override]
    public function setMessageStructure(string $structure): void
    {
        $this->messageStructure = $structure;
    }
}
