<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker\Amazon\SNS;

use BackQ\Worker\AbstractWorker;
use BackQ\Worker\Amazon\SNS\SnsClient as AwsSnsClient;

abstract class Application extends AbstractWorker
{
    /** @var $snsClient AwsSnsClient */
    protected $snsClient;

    /**
     * Sets up a client that will Publish SNS messages
     *
     * @param AwsSnsClient $awsSnsClient
     */
    public function setClient($awsSnsClient)
    {
        $this->snsClient = $awsSnsClient;
    }
}
