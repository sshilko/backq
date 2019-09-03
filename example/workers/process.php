<?php

include_once '../../vendor/autoload.php';

/**
 * Worker
 * 
 * Execute processes jobs from the queue
 * Launches a worker that listens for jobs on default queue="process"
 *
 * Copyright (c) 2019 Sergei Shilko <contact@sshilko.com>
 */

$worker = new \BackQ\Worker\AProcess(new \BackQ\Adapter\Beanstalk);
$worker->run();
