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

final class Publish extends AbstractWorker
{
    protected $queueName;

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    /**
     * Time to run for a job with the remove queue as destination
     */
    const REMOVE_JOBTTR_VAL = 15;

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $queueSuffix = strtolower(end(explode('\\', get_called_class())));
        $this->setQueueName('aws_sns_endpoints_' . $queueSuffix . '_');

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
        return substr($this->queueName, strpos($this->queueName, '_') + 1);
    }

    public function run()
    {
        $this->debug('Started');
        $connected = $this->start();

        if ($connected) {

            try {
                $this->debug('Connected to queue');

                /** @var $endpointsPublisher \BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Remove */
                $removeEndpointsPublisher = \BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Remove::getInstance(new \BackQ\Adapter\Beanstalk());
                $removeEndpointsPublisher->setQueueName($removeEndpointsPublisher->getQueueName() . $this->getPlatform());
                $removeEndpointsPublisherStart = $removeEndpointsPublisher->start();

                $work = $this->work();
                $this->debug('After init work generator');

                /**
                 * Attempt sending all messages in the queue
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('Got some work');

                    $message     = @unserialize($payload);
                    $processed   = true;
                    $validDevice = true;

                    if (!($message instanceof \ns\Push\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Message)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    } else {
                        try {
                            $result = $this->snsClient->publish(['Message'           => $message->toJson(),
                                                                 'MessageAttributes' => $message->getAttributes(),
                                                                 'TargetArn'         => $message->getTargetArn()]);

                            $this->debug('SNS Client delivered message to endpoint');
                        } catch (\Aws\Sns\Exception\SnsException $e) {
                            if (in_array($e->getAwsErrorCode(), ['InvalidParameter', 'EndpointDisabled'])) {
                                $validDevice = false;
                            }
                            $this->debug($e->getAwsErrorCode());
                        } catch (\Exception $networkError) {
                            /**
                             * Other errors (Network errors) won't cause any effects
                             */
                        } finally {
                            $work->send(true === $processed);
                        }

                        if (!$validDevice) {
                            /**
                             * On disabled endpoint, send to queue to process invalid tokens/devices/endpoints
                             * Same case on InvalidParameter, as this is the error after trying to publish to a deleted endpoint
                             */
                            if ($removeEndpointsPublisherStart && $removeEndpointsPublisher->hasWorkers()) {
                                /**
                                 * Setup fields needed to complete an operation to remove an endpoint
                                 */
                                $removeMessage = new \ns\Push\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RemoveMessage();
                                $removeMessage->addDeviceId($message->getDeviceId());
                                $removeMessage->addToken($message->getToken());
                                $removeMessage->addEndpointUUID($message->getEndpointUUID());
                                $removeMessage->setEndpointArn($this->getPlatform());
                                $removeMessage->setService($message->getService());

                                $result = $removeEndpointsPublisher->publish($removeMessage,
                                                                             [\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => self::REMOVE_JOBTTR_VAL]);
                                if ($result <= 0) {
                                    @error_log('Failed to publish endpoint to queue for deleting endpoint');
                                }
                            }
                        }
                    }
                };
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] SNS worker exception: ' . $e->getMessage());
            }
        }

        /**
         * Finish removeEndpoints publisher
         */
        $this->finish();
    }
}
