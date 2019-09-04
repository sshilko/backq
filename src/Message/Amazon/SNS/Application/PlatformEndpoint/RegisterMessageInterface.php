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

interface RegisterMessageInterface
{
    public function getAttributes() : array;
    public function setAttributes(array $attrs);

    public function getApplicationArn() : string;
    public function setApplicationArn(string $appArn);

    public function getToken() : string;
    public function addToken(string $token);
}
