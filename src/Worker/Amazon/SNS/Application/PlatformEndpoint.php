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

use BackQ\Worker\Amazon\SNS\Application;

abstract class PlatformEndpoint extends Application
{
    protected $queueName = 'aws_sns_endpoints_';

    /**
     * Maximum number of times that the same Job can attempt to be reprocessed
     * after an error that it could be recovered from in a next iteration
     */
    const RETRY_MAX = 3;

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $className   = explode('\\', get_called_class());
        $className   = end($className);
        $queueSuffix = strtolower($className) . '_';
        $this->setQueueName($this->getQueueName() . $queueSuffix);

        parent::__construct($adapter);
    }

    /**
     * Platform that an endpoint will be registered into, can be extracted from
     * the queue name
     *
     * @return string
     */
    public function getPlatform()
    {
        return substr($this->queueName, strrpos($this->queueName, '_') + 1);
    }
}
