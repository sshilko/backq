<?php
/**
 * Worker
 * 
 * Execute processes
 * Launches a worker that listens for jobs on default queue="process"
 *
 * Copyright (c) 2017 Sergei Shilko <contact@sshilko.com>
 */

$worker = new \BackQ\Worker\AProcess(new \BackQ\Adapter\Beanstalk);
$worker->run();
