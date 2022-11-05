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

abstract class Register extends AbstractPublisher
{

    /**
     * The queue will be used to create AWS platform endpoints
     */
    protected string $queueName = 'aws_sns_endpoints_register_';
}
