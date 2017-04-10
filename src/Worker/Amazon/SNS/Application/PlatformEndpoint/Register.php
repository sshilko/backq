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
use \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RegisterMessageInterface;

class Register extends PlatformEndpoint
{
    public function run()
    {
        $this->debug('Started');
        $connected = $this->start();
        if ($connected) {

            try {
                $this->debug('Connected to queue');

                $workTimeout = 15;
                $work = $this->work($workTimeout);
                $this->debug('After init work generator');

                /**
                 * Keep an array with taskId and the number of times it was
                 * attempted to be reprocessed to avoid starvation
                 */
                $reprocessedTasks = [];

                /**
                 * Now attempt to register all devices
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('Got some work');

                    if (!$payload && $workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }
                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof RegisterMessageInterface)) {
                        $work->send(true);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    try {
                        $endpointResult = $this->snsClient->createPlatformEndpoint([
                            'PlatformApplicationArn' => $message->getApplicationArn(),
                            'Token'                  => $message->getToken(),
                            'Attributes'             => $message->getAttributes()
                        ]);
                    } catch (\Exception $e) {

                        if (is_subclass_of('\BackQ\Worker\Amazon\SNS\Client\Exception\SnsException',
                                           get_class($e))) {
                            /**
                             * We can't do anything on specific errors and then the job is marked as processed
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_CreatePlatformEndpoint.html#API_CreatePlatformEndpoint_Errors
                             * @var $e SnsException
                             */
                            if (in_array($e->getAwsErrorCode(),
                                         [SnsException::AUTHERROR,
                                          SnsException::INVALID_PARAM,
                                          SnsException::NOTFOUND])) {
                                $work->send(true);
                                continue;
                            }

                            /**
                             * An internal server error will be considered as a
                             * temporary issue and we can retry creating the endpoint
                             * Same process for general network issues
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

                                        /**
                                         * Network error or AWS Internal or other stuff we cant fix,
                                         * pretend it worked
                                         */
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
                     * Save the new Application endpoint into database
                     * If something fails, retry the whole process
                     */
                    if (!empty($endpointResult['EndpointArn'])) {
                        $result = $this->onSuccess($endpointResult['EndpointArn'], $message);

                        if (!$result) {
                            $work->send(false);
                            break;
                        }
                        $this->debug('Endpoint registered successfully on Service provider and backend');
                    } else {
                        $processed = false;
                    }
                    $work->send(true === $processed);
                }
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] Register SNS worker exception: ' . $e->getMessage());
            }
        }
        $this->finish();
    }

    /**
     * Handles registering a successfully created Amazon endpoint
     *
     * @param string $endpointArn
     * @param        $message
     *
     * @return bool
     */
    protected function onSuccess(string $endpointArn, \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register $message)
    {
        return true;
    }
}
