<?php
/**
 * Copyright (c) 2017, Sergei Shilko <contact@sshilko.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice,
 *       this list of conditions and the following disclaimer in the documentation
 *       and/or other materials provided with the distribution.
 *
 *    3. Neither the name of Sergei Shilko nor the names of its contributors
 *       may be used to endorse or promote products derived from this software
 *       without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
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
