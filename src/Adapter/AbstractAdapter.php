<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 **/

namespace BackQ\Adapter;

abstract class AbstractAdapter
{
    const PARAM_JOBTTR    = 'jobttr';
    const PARAM_READYWAIT = 'readywait';

    const JOBTTR_DEFAULT  = 60;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Connect to server
     */
    abstract public function connect();

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
     * @param int $timeout seconds
     */
    abstract public function pickTask();

    /**
     * Put job to process
     */
    abstract public function putTask($body, $params = array());

    /**
     * Acknowledge server: callback after successfully processing job
     */
    abstract public function afterWorkSuccess($workId);

    /**
     * Acknowledge server: callback after failing to process job
     */
    abstract public function afterWorkFailed($workId);

    /**
     * Ping if still has alive connection to server
     */
    abstract public function ping();

    /**
     * Is there workers ready for job immediately
     */
    abstract public function hasWorkers($queue);

    /**
     * Preffered limit of one work cycle
     * @param int|null $seconds
     *
     * @return null
     */
    abstract public function setWorkTimeout(int $seconds = null);

    public function setLogger(\Psr\Log\LoggerInterface $logger) : void {
        $this->logger = $logger;
    }}
