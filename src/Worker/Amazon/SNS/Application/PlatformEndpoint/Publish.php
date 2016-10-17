<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Tripod Technology GmbH
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

namespace BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Worker\AbstractWorker;

final class Publish extends AbstractWorker
{
    protected $queueName = 'aws_sns_endpoints_publish';

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    protected $app;

    protected $platform;

    protected $accountId;

    /**
     * Time to run for a job with the remove queue as destination
     */
    const REMOVE_JOBTTR_VAL = 3;

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
     * Platform that the notifications will be published to
     *
     * @param $platform
     */
    public function setPlatform($platform)
    {
        $this->platform = $platform;
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
     * Sets the name of the Application Platform the worker client has to publish to
     *
     * @param string $app
     */
    public function setApp($app)
    {
        $this->app = $app;
    }

    /**
     * Sets up AWS account Id
     * @param $accountId
     */
    public function setAccountId($accountId)
    {
        $this->accountId = $accountId;
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
                $removeEndpointsPublisher->setQueueName('aws_sns_endpoints_remove_' . $this->platform);
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
                                                                 'TargetArn'         => $message->getEndpointArn($this->platform,
                                                                                                                 $this->app,
                                                                                                                 $this->accountId,
                                                                                                                 $this->snsClient->getRegion())]);
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
        //$removeEndpointsPublisher->
        $this->finish();
    }
}
