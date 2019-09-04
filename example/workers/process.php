<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

include_once '../../vendor/autoload.php';

/**
 * Worker
 * 
 * Execute processes jobs from the queue
 * Launches a worker that listens for jobs on default queue="process"
 */

$worker = new \BackQ\Worker\AProcess(new \BackQ\Adapter\Beanstalk);
$worker->run();
