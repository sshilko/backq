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

class Register extends PlatformEndpoint
{
    /**
     * Maximum number of times that the same Job can attempt to be reprocessed
     * after an error that it could be recovered from in a next iteration
     */
    const RETRY_MAX = 3;
    
    public function run()
    {
        $this->debug('Started');
        $connected = $this->start();
        if ($connected) {

            try {
                $this->debug('Connected to queue');

                $work = $this->work(15);
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

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    } else {
                        try {
                            $endpointResult = $this->snsClient->createPlatformEndpoint([
                                'PlatformApplicationArn' => $message->getApplicationArn(),
                                'Token'                  => $message->getToken(),
                                'Attributes'             => $message->getAttributes()
                            ]);
                        } catch (SnsException $e) {
                            /**
                             * We can't do anything on specific errors and then
                             * the job is marked as processed
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_CreatePlatformEndpoint.html#API_CreatePlatformEndpoint_Errors
                             */
                            if (in_array($e->getAwsErrorCode(),
                                        [SnsException::AUTHERROR,
                                         SnsException::INVALID_PARAM,
                                         SnsException::NOTFOUND])) {
                                $work->send(true === $processed);
                                break;
                            }

                            /**
                             * An internal server error will be considered as a
                             * temporary issue and we can retry creating the endpoint
                             */
                            if (SnsException::INTERNAL == $e->getAwsErrorCode()) {
                                /**
                                 * Only retry if the max threshold has not been reached
                                 */
                                if (array_key_exists($taskId, $reprocessedTasks)) {
                                    if ($reprocessedTasks >= self::RETRY_MAX) {
                                        $this->debug('Retried re-processing the same job too many times');
                                        unset($reprocessedTasks[$taskId]);

                                        $work->send(true === $processed);
                                        break;
                                    }
                                    $reprocessedTasks[$taskId] += 1;
                                } else {
                                    $reprocessedTasks[$taskId] = 1;
                                }
                                $work->send(false);
                                break;
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
