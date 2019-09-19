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

class Remove implements RemoveMessageInterface
{
    /**
     * Amazon Resource name that uniquely identifies an endpoint that wil be removed from Aws
     *
     * @var string
     */
    protected $endpointArn;

    /**
     * Returns the Amazon Resource Name for the endpoint to delete
     *
     * @return string
     */
    public function getEndpointArn() : string
    {
        return $this->endpointArn;
    }

    /**
     * Sets up an Amazon Resource Name from an endpoint to remove
     *
     * @param string $arn
     */
    public function setEndpointArn(string $arn)
    {
        $this->endpointArn = $arn;
    }
}
