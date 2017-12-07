<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2017 Sergei Shilko <contact@sshilko.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 **/

namespace BackQ\Message\Amazon\SNS\Application\PlatformEndpoint;

class Register implements RegisterMessageInterface
{
    /**
     * Associative array of string keys mapping to values, they'll be the attributes
     * set for an endpoint and could vary depending on the platform application
     *
     * @var array
     */
    protected $attributes;

    /**
     * Unique identifier created by the notification service for an app on a device
     *
     * @var string
     */
    protected $token;

    /**
     * Amazon Resource Identifier of the Platform application that an endpoint
     * will be registered in
     *
     * @var string
     */
    protected $applicationArn;

    /**
     * Get the specific attributes to create endpoints
     * @return array
     */
    public function getAttributes() : array
    {
        return $this->attributes;
    }

    /**
     * Sets attributes specific to different platforms in order to publish a message
     *
     * @param array $attrs
     */
    public function setAttributes(array $attrs)
    {
        $this->attributes = $attrs;
    }
    /**
     * Get the resource name for the Application Platform where an endpoint
     * where an endpoint will be saved
     * @return string
     */
    public function getApplicationArn() : string
    {
        return $this->applicationArn;
    }

    /**
     * Sets up the Resource Number for a Platform Application
     * @param $appArn
     */
    public function setApplicationArn(string $appArn)
    {
        $this->applicationArn = $appArn;
    }

    /**
     * Gets the token or identifier for the device to register
     * @return string
     */
    public function getToken() : string
    {
        return $this->token;
    }

    /**
     * Adds a unique identifier created by the notification service for the app on a device
     * @param $token
     */
    public function addToken(string $token)
    {
        $this->token = $token;
    }
}
