<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker\Amazon\SNS\Application;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Worker\Amazon\SNS\Application;
use function end;
use function explode;
use function strrpos;
use function strtolower;
use function substr;

abstract class PlatformEndpoint extends Application
{
    /**
     * Maximum number of times that the same Job can attempt to be reprocessed
     * after an error that it could be recovered from in a next iteration
     */
    public const RETRY_MAX = 3;

    protected $queueName = 'aws_sns_endpoints_';

    public function __construct(AbstractAdapter $adapter)
    {
        $className   = explode('\\', static::class);
        $className   = end($className);
        $queueSuffix = strtolower($className) . '_';
        $this->setQueueName($this->getQueueName() . $queueSuffix);

        parent::__construct($adapter);
    }

    /**
     * Platform that an endpoint will be registered into, can be extracted from
     * the queue name
     *
     */
    public function getPlatform(): string
    {
        return substr($this->queueName, strrpos($this->queueName, '_') + 1);
    }
}
