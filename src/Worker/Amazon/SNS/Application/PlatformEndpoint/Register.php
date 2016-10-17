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

final class Register extends AbstractWorker
{
    protected $queueName = 'aws_sns_endpoints_register';

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    protected $platform;

    protected $applicationArn;

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
     * Sets up a client that will Create AWS SNS Endpoints
     *
     * @param $client
     */
    public function setClient($client)
    {
        $this->snsClient = $client;
    }

    /**
     * Platform that an endpoint will be registered into
     *
     * @param $platform
     */
    public function setPlatform($platform)
    {
        $this->platform = $platform;
    }

    /**
     * Specific Resource Number for the Platform application where the endpoint will be created
     *
     * @param $applicationArn
     */
    public function setApplicationArn($applicationArn)
    {
        $this->applicationArn = $applicationArn;
    }
    
    public function run()
    {
        $this->debug('Started');
        $connected = $this->start();
        if ($connected) {

            try {
                $this->debug('Connected to queue');

                $work = $this->work();
                $this->debug('After init work generator');

                /**
                 * Now attempt to register all devices
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('Got some work');

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \ns\Push\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RegisterMessage)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                    } else {
                        try {
                            $endpointResult = $this->snsClient->createPlatformEndpoint([
                                'PlatformApplicationArn' => $this->applicationArn,
                                'Token'                  => $message->getToken(),
                                'Attributes'             => $message->getAttributes()
                            ]);
                        } catch (\Aws\Sns\Exception\SnsException $e) {

                        }

                        /**
                         * Save the new Application endpoint into database
                         * If something fails, retry the whole process
                         */
                        if (!empty($endpointResult['EndpointArn'])) {
                            $serviceProvider = \ns\Nstokenprovider\Service::getServiceProvider($message->getService(), $this->platform);
                            $result = $serviceProvider->register($message->getDeviceId(), $endpointResult['EndpointArn']);

                            if (!$result) {
                                $work->send(false);
                                break;
                            }
                            $this->debug('Endpoint registered successfully on Service provider and backend');
                            $work->send(true === $processed);
                        }
                    }
                }
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] Register SNS worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
