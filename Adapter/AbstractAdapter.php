<?php
namespace BackQ\Adapter;

abstract class AbstractAdapter
{
    /**
     * Connect to server
     */
    abstract public function connect();

    /**
     * Report some errors
     */
    abstract public function error($msg);

    /**
     * Disconnect from server
     */
    abstract public function disconnect();

    /**
     * Subscribe to server queue
     */
    abstract public function bindRead($queue);

    /**
     * Prepare to write to server queue
     */
    abstract public function bindWrite($queue);

    /**
     * Get job to process
     */
    abstract public function pickTask();

    /**
     * Put job to process
     */
    abstract public function putTask($body, $params = array());

    /**
     * Acknowledge server: callback after successfully processing job
     */
    abstract protected function afterWorkSuccess($workId);

    /**
     * Acknowledge server: callback after failing to process job
     */
    abstract protected function afterWorkFailed($workId);

    /**
     * Ping if still has alive connection to server
     */
    abstract public function ping();

    /**
     * Is there workers ready for job immediately
     */
    abstract public function hasWorkers($queue);

}
