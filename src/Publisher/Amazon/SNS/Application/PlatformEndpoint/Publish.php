<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Publisher\AbstractPublisher;

abstract class Publish extends AbstractPublisher
{
    /**
     * The queue will be used to publish to Aws endpoints
     * @var string
     */
    protected $queueName = 'aws_sns_endpoints_publish_';
}
