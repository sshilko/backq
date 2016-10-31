<?php
/**
 * Copyright (c) 2016, Tripod Technology GmbH <support@tandem.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice,
 *       this list of conditions and the following disclaimer in the documentation
 *       and/or other materials provided with the distribution.
 *
 *    3. Neither the name of Tripod Technology GmbH nor the names of its contributors
 *       may be used to endorse or promote products derived from this software
 *       without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 **/

namespace BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Worker\AbstractWorker;

class Remove extends AbstractWorker
{
    protected $queueName = 'aws_sns_endpoints_';

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $queueSuffix = strtolower(end(explode('\\', get_called_class()))) . '_';
        $this->setQueueName($this->getQueueName() . $queueSuffix);

        parent::__construct($adapter);
    }

    /**
     * Queue this worker is read from
     *
     * @return string
     */
    public function getQueueName()
    {
        return $this->queueName;
    }

    /**
     * Sets up a client that will Publish SNS messages
     *
     * @param $client
     */
    public function setClient($client)
    {
        $this->snsClient = $client;
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

    public function run()
    {
        $this->debug('started');
        $connected = $this->start();
        if ($connected)  {
            $client = null;
            try {
                $this->debug('connected to queue');

                $work = $this->work();
                $this->debug('after init work generator');

                /**
                 * Process all messages that were published pointing to a disabled or non existing endpoint
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    $message   = @unserialize($payload);
                    $processed = true;
                    if (!($message instanceof \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    /**
                     * Remove the endpoint from Amazon SNS; this won't result in an
                     * exception if the resource was already deleted
                     */
                    try {
                        /**
                         * Endpoint creation is idempotent, then there will always be one Arn per token
                         * @see http://docs.aws.amazon.com/sns/latest/api/API_DeleteEndpoint.html#API_DeleteEndpoint_Errors
                         */
                        $this->snsClient->deleteEndpoint(['EndpointArn' => $message->getEndpointArn()]);

                    } catch (\Aws\Sns\Exception\SnsException $e) {
                        /**
                         * With issues regarding Authorization or parameters, nothing to be done
                         * @see http://docs.aws.amazon.com/sns/latest/api/API_DeleteEndpoint.html
                         */
                        if (in_array($e->getAwsErrorCode(), ['AuthorizationError', 'InvalidParameter'])) {
                            $work->send(true === $processed);
                            break;
                        }

                        /**
                         * Retry deletion on Internal Server error
                         */
                        if ('InternalError' == $e->getAwsErrorCode()) {
                            $work->send(false);
                            break;
                        }
                    }

                    /**
                     * Proceed un-registering the device and endpoint (managed by the token provider)
                     * Retry sending the job to the queue on error/problems deleting
                     */
                    $this->debug('Deleting device with token ' . $message->getToken());
                    $delSuccess = $this->onSuccess($message);

                    if (!$delSuccess) {
                        $work->send(false);
                        break;
                    }
                    $this->debug('Endpoint/Device successfully deleted on Service provider and backend');
                    $work->send(true === $processed);
                }

            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] Remove endpoints worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
