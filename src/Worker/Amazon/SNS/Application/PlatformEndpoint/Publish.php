<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\PublishMessageInterface;

class Publish extends PlatformEndpoint
{
    public $workTimeout = 5;

    public function run()
    {
        $this->logDebug('Started');
        $connected = $this->start();

        if ($connected) {

            try {
                $this->logDebug('Connected to queue');

                $work = $this->work();
                $this->logDebug('After init work generator');

                /**
                 * Keep an array with taskId and the number of times it was
                 * attempted to be reprocessed to avoid starvation
                 */
                $reprocessedTasks = [];

                /**
                 * Attempt sending all messages in the queue
                 */
                foreach ($work as $taskId => $payload) {
                    $this->logDebug('got some work: ' . ($payload ? 'yes' : 'no'));

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }

                    $message = @unserialize($payload);
                    if (!($message instanceof PublishMessageInterface)) {
                        $work->send(true);
                        $this->logDebug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    try {
                        $payload = ['Message'   => $message->getMessage(),
                                    'TargetArn' => $message->getTargetArn()];

                        $attributes = $message->getAttributes();
                        if ($attributes) {
                            $payload['MessageAttributes'] = $attributes;
                        }

                        $messageStructure = $message->getMessageStructure();
                        if ($messageStructure) {
                            $payload['MessageStructure'] = $messageStructure;
                        }

                        $this->snsClient->publish($payload);

                        $this->logDebug('SNS Client delivered message to endpoint');
                    } catch (\Exception $e) {

                        if (is_subclass_of('\BackQ\Worker\Amazon\SNS\Client\Exception\SnsException',
                                           get_class($e))) {

                            /**
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_Publish.html#API_Publish_Errors
                             * @var $e SnsException
                             */
                            $this->logDebug('Could not publish to endpoint with error ' . $e->getAwsErrorCode());

                            /**
                             * When an endpoint was marked as disabled or the
                             * request is not valid, the operation can't be performed
                             * and the endpoint should be removed, send to the specific queue
                             */
                            if ($e->getAwsErrorCode()) {
                                /**
                                 * Current job to be processed by current queue but
                                 * will send it to a queue to remove endpoints
                                 */
                                $this->onFailure($message, $e->getAwsErrorCode());
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
                                        $this->logDebug('Retried re-processing the same job too many times');
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
                        } else {
                            $this->logDebug('Hard error: ' . $e->getMessage());
                            trigger_error(__CLASS__ . ' ' . $e->getMessage(), E_USER_WARNING);
                        }
                    } finally {
                        $work->send(true);
                    }
                };
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] SNS worker exception: ' . $e->getMessage());
            }
        } else {
            $this->logDebug('Unable to connect');
        }

        $this->finish();
    }

    /**
     * Handles a different flow when Publishing can't be completed
     *
     * @param \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish $message
     * @param string $getAwsErrorCode
     *
     * @return null
     */
    protected function onFailure(\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish $message, string $getAwsErrorCode)
    {
        return null;
    }
}
