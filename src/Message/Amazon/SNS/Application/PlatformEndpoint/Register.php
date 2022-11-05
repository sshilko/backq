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

class Register implements RegisterMessageInterface
{

    /**
     * Associative array of string keys mapping to values, they'll be the attributes
     * set for an endpoint and could vary depending on the platform application
     *
     * @var array
     */
    protected array $attributes;

    /**
     * Unique identifier created by the notification service for an app on a device
     *
     */
    protected string $token;

    /**
     * Amazon Resource Identifier of the Platform application that an endpoint
     * will be registered in
     *
     */
    protected string $applicationArn;

    /**
     * Get the specific attributes to create endpoints
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

    /**
     * Get the resource name for the Application Platform where an endpoint
     * where an endpoint will be saved
     */
    public function getApplicationArn(): string
    {
        return $this->applicationArn;
    }

    /**
     * Sets up the Resource Number for a Platform Application
     * @param $appArn
     */
    public function setApplicationArn(string $appArn): void
    {
        $this->applicationArn = $appArn;
    }

    /**
     * Gets the token or identifier for the device to register
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Adds a unique identifier created by the notification service for the app on a device
     * @param $token
     */
    public function addToken(string $token): void
    {
        $this->token = $token;
    }
}
