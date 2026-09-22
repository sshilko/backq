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

class Remove implements RemoveMessageInterface
{

    /**
     * Amazon Resource name that uniquely identifies an endpoint that wil be removed from Aws
     *
     */
    protected string $endpointArn = '';

    /**
     * Returns the Amazon Resource Name for the endpoint to delete
     *
     */
    #[Override]
    public function getEndpointArn(): string
    {
        return $this->endpointArn;
    }

    /**
     * Sets up an Amazon Resource Name from an endpoint to remove
     *
     * @param string $arn
     */
    #[Override]
    public function setEndpointArn(string $arn): void
    {
        $this->endpointArn = $arn;
    }
}
