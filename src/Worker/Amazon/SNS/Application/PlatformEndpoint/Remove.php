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

use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RemoveMessageInterface;

class Remove extends PlatformEndpoint
{
    public $workTimeout = 5;

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
                 * Keep an array with taskId and the number of times it was
                 * attempted to be reprocessed to avoid starvation
                 */
                $reprocessedTasks = [];

                /**
                 * Process all messages that were published pointing to a disabled or non existing endpoint
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }

                    $message = @unserialize($payload);

                    if (!($message instanceof RemoveMessageInterface)) {
                        $work->send(true);
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

                    } catch (\Exception $e) {

                        if (is_subclass_of('\BackQ\Worker\Amazon\SNS\Client\Exception\SnsException',
                                           get_class($e))) {

                            /**
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_DeleteEndpoint.html#API_DeleteEndpoint_Errors
                             * @var $e SnsException
                             */
                            $this->debug('Could not delete endpoint with error: ' . $e->getAwsErrorCode());

                            /**
                             * With issues regarding Authorization or parameters, nothing
                             * can be done, mark as processed
                             */
                            if (in_array($e->getAwsErrorCode(), [SnsException::AUTHERROR,
                                                                 SnsException::INVALID_PARAM])) {
                                $work->send(true);
                                continue;
                            }

                            /**
                             * Retry deletion on Internal Server error from Service
                             * or general network exceptions
                             */
                            if (SnsException::INTERNAL == $e->getAwsErrorCode() ||
                                is_subclass_of('\BackQ\Worker\Amazon\SNS\Client\Exception\NetworkException',
                                               get_class($e->getPrevious()))) {
                                /**
                                 * Only retry if the max threshold has not been reached
                                 */
                                if (isset($reprocessedTasks[$taskId])) {

                                    if ($reprocessedTasks[$taskId] >= self::RETRY_MAX) {
                                        $this->debug('Retried re-processing the same job too many times');
                                        unset($reprocessedTasks[$taskId]);

                                        $work->send(true);
                                        continue;
                                    }
                                    $reprocessedTasks[$taskId] += 1;
                                } else {
                                    $reprocessedTasks[$taskId] = 1;
                                }

                                $work->send(false);
                                continue;
                            }
                        }
                    }

                    /**
                     * Proceed un-registering the device and endpoint (managed by the token provider)
                     * Retry sending the job to the queue on error/problems deleting
                     */
                    $this->debug('Deleting device with Arn ' . $message->getEndpointArn());
                    $delSuccess = $this->onSuccess($message);

                    if (!$delSuccess) {
                        /**
                         * @todo what happens onSuccess if it fails?
                         */
                        $work->send(false);
                        continue;
                    } else {
                        $this->debug('Endpoint/Device successfully deleted on Service provider and backend');
                        $work->send(true);
                    }
                }

            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] Remove endpoints worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }

    /**
     * Handles actions to be performed on correct deletion of an amazon endpoint
     * @param \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove $message
     *
     * @return bool|array
     */
    protected function onSuccess(\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove $message)
    {
        return true;
    }
}
