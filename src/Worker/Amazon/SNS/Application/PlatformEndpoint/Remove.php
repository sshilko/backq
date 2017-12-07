<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2017 Sergei Shilko <contact@sshilko.com>
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

use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RemoveMessageInterface;

class Remove extends PlatformEndpoint
{
    public function run()
    {
        $this->debug('started');
        $connected = $this->start();
        if ($connected)  {
            $client = null;
            try {
                $this->debug('connected to queue');

                $workTimeout = 5;
                $work = $this->work($workTimeout);
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

                    if (!$payload && $workTimeout > 0) {
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
