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

                $workTimeout = 5;
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
