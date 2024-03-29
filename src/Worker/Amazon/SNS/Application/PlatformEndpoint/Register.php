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

use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\RegisterMessageInterface;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use Throwable;
use function date;
use function error_log;
use function get_class;
use function gettype;
use function in_array;
use function is_subclass_of;
use function unserialize;

class Register extends PlatformEndpoint
{

    public $workTimeout = 5;

    public function run(): void
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
                 * Now attempt to register all devices
                 */
                foreach ($work as $taskId => $payload) {
                    $this->logDebug('Got some work');

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }
                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof RegisterMessageInterface)) {
                        $work->send(true);
                        $this->logDebug('Worker does not support payload of: ' . gettype($message));

                        continue;
                    }

                    try {
                        $endpointResult = $this->snsClient->createPlatformEndpoint([
                            'PlatformApplicationArn' => $message->getApplicationArn(),
                            'Token'                  => $message->getToken(),
                            'Attributes'             => $message->getAttributes(),
                        ]);
                    } catch (Throwable $e) {
                        if (is_subclass_of(
                            '\BackQ\Worker\Amazon\SNS\Client\Exception\SnsException',
                            get_class($e)
                        )) {
                            /**
                             * We can't do anything on specific errors and then the job is marked as processed
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_CreatePlatformEndpoint.html#API_CreatePlatformEndpoint_Errors
                             * @var $e SnsException
                             */
                            if (in_array(
                                $e->getAwsErrorCode(),
                                [SnsException::AUTHERROR,
                                    SnsException::INVALID_PARAM,
                                    SnsException::NOTFOUND]
                            )) {
                                $work->send(true);

                                continue;
                            }

                            /**
                             * An internal server error will be considered as a
                             * temporary issue and we can retry creating the endpoint
                             * Same process for general network issues
                             */
                            if (SnsException::INTERNAL === $e->getAwsErrorCode() ||
                                is_subclass_of(
                                    '\BackQ\Worker\Amazon\SNS\Client\Exception\NetworkException',
                                    get_class($e->getPrevious())
                                )) {
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
                        $this->logDebug('Endpoint registered successfully on Service provider and backend');
                    } else {
                        $processed = false;
                    }
                    $work->send(true === $processed);
                }
            } catch (Throwable $e) {
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
     */
    protected function onSuccess(
        string $endpointArn,
        \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register $message
    ): bool {
        return true;
    }
}
