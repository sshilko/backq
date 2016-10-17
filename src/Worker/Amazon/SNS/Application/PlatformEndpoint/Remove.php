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

final class Remove extends AbstractWorker
{
    protected $queueName = 'aws_sns_endpoints_remove';

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    protected $app;
    protected $platform;
    protected $accountId;
    protected $provider;

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
     * Platform that an endpoint was saved into
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
     * Sets up the token provider associated to the platform
     *
     * @param $provider
     */
    public function setProvider($provider)
    {
        $this->provider = $provider;
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
                    if (!($message instanceof \ns\Push\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RemoveMessage)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    /**
                     * Remove the endpoint from Amazon SNS; this won't result in an
                     * exception if the resource was already deleted
                     */
                    try {
                        $this->snsClient->deleteEndpoint(['EndpointArn' => $message->getEndpointArn($this->platform,
                                                                                                    $this->app,
                                                                                                    $this->accountId,
                                                                                                    $this->snsClient->getRegion())]);
                    } catch (\Aws\Sns\Exception\SnsException $e) {
                        if ('InternalError' == $e->getAwsErrorCode()) {

                        }
                    }

                    /**
                     * Proceed unregistering the device and endpoint (managed by the token provider)
                     * Retry sending the job to the queue on error/problems deleting
                     */
                    $this->debug('Deleting device with token ' . $message->getToken());

                    $serviceProvider = \ns\Nstokenprovider\Service::getServiceProvider($message->getService(), $this->platform);
                    $delSuccess      = $serviceProvider->remove($message->getDeviceId(), $message->getToken(), $message->getEndpointUUID());

                    if (!$delSuccess) {
                        $work->send(false);
                        break;
                    }
                    $this->debug('Endpoint/Device successfully deleted on Service provider and backend');
                    $work->send(true === $processed);
                }

            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] Endpoints worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
