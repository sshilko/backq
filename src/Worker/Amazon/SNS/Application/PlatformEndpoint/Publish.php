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
use \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\PublishMessageInterface;

class Publish extends PlatformEndpoint
{
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
                 * Keep an array with taskId and the number of times it was
                 * attempted to be reprocessed to avoid starvation
                 */
                $reprocessedTasks = [];

                /**
                 * Attempt sending all messages in the queue
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('Got some work');

                    $message     = @unserialize($payload);

                    if (!($message instanceof PublishMessageInterface)) {
                        $work->send(true);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    try {
                        $this->snsClient->publish(['Message'           => $message->getMessage(),
                                                   'MessageAttributes' => $message->getAttributes(),
                                                   'TargetArn'         => $message->getTargetArn()]);

                        $this->debug('SNS Client delivered message to endpoint');
                    } catch (\Exception $e) {

                        if (is_subclass_of('\BackQ\Worker\Amazon\SNS\Client\Exception\SnsException',
                                           get_class($e))) {

                            /**
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_Publish.html#API_Publish_Errors
                             * @var $e SnsException
                             */
                            $this->debug('Could not publish to endpoint with error ' . $e->getAwsErrorCode());

                            /**
                             * When an endpoint was marked as disabled or the
                             * request is not valid, the operation can't be performed
                             * and the endpoint should be removed, send to the specific queue
                             */
                            if (in_array($e->getAwsErrorCode(), [SnsException::INVALID_PARAM,
                                                                 SnsException::ENDPOINT_DISABLED])) {
                                /**
                                 * Current job to be processed by current queue but
                                 * will send it to a queue to remove endpoints
                                 */
                                $this->onFailure($message);
                            }

                            /**
                             * Aws Internal errors and general network error
                             * will cause the job to be sent back to queue
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
                                /**
                                 * Send back to queue for re-try (maybe another process/worker, so
                                 * max retry = NUM_WORKERS*NUM_RETRIES)
                                 */
                                $work->send(false);
                                continue;
                            }
                        }
                    } finally {
                        $work->send(true);
                    }
                };
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] SNS worker exception: ' . $e->getMessage());
            }
        } else {
            $this->debug('Unable to connect');
        }

        $this->finish();
    }

    /**
     * Handles a different flow when Publishing can't be completed
     *
     * @param \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish $message
     *
     * @return null
     */
    protected function onFailure(\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish $message)
    {
        return null;
    }
}
