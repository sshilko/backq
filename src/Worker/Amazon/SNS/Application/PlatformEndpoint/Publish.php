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

use BackQ\Worker\AbstractWorker;

class Publish extends AbstractWorker
{
    protected $queueName = 'aws_sns_endpoints_';

    /** @var $snsClient \Aws\Sns\SnsClient */
    protected $snsClient;

    public function __construct(\BackQ\Adapter\AbstractAdapter $adapter)
    {
        $queueSuffix = strtolower(end(explode('\\', get_called_class()))) . '_';
        $this->setQueueName($this->getQueueName() . $queueSuffix);

        parent::__construct($adapter);
    }

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
     * Sets up a client that will Publish SNS messages
     *
     * @param $client
     */
    public function setClient($client)
    {
        $this->snsClient = $client;
    }

    /**
     * Platform that an endpoint will be registered into, can be extracted from
     * the queue name
     *
     * @return string
     */
    public function getPlatform()
    {
        return substr($this->queueName, strpos($this->queueName, '_') + 1);
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
                 * Attempt sending all messages in the queue
                 */
                foreach ($work as $taskId => $payload) {
                    $this->debug('Got some work');

                    $message     = @unserialize($payload);
                    $processed   = true;

                    if (!($message instanceof \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish)) {
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    } else {
                        try {
                            $this->snsClient->publish(['Message'           => $message->toJson(),
                                                       'MessageAttributes' => $message->getAttributes(),
                                                       'TargetArn'         => $message->getTargetArn()]);

                            $this->debug('SNS Client delivered message to endpoint');
                        } catch (\Aws\Sns\Exception\SnsException $e) {
                            /**
                             * Network errors will cause the job to be sent back to queue
                             * @see http://docs.aws.amazon.com/sns/latest/api/API_Publish.html#API_Publish_Errors
                             */
                            if (in_array($e->getAwsErrorCode(), ['InvalidParameter', 'EndpointDisabled'])) {
                                /**
                                 * Current job to be processed by current queue but
                                 * will send it to a queue to remove endpoints
                                 */
                                $this->onFailure($message);
                            } elseif ('InternalError' == $e->getAwsErrorCode()) {
                                $processed = false;
                            }
                            $this->debug($e->getAwsErrorCode());
                        } catch (\RequestException $networkError) {
                            /**
                             * Other errors (Network errors) won't cause any effects
                             * and the job can be retried
                             */
                            $processed = false;
                            $this->debug('Could not publish to endpoint with error ' . $networkError->getMessage());
                        } finally {
                            $work->send(true === $processed);
                        }
                    }
                };
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] SNS worker exception: ' . $e->getMessage());
            }
        }

        /**
         * Finish removeEndpoints publisher
         */
        $this->finish();
    }

    /**
     * Handles a different flow when Publishing can't be completed
     *
     * @param $message
     *
     * @return bool
     */
    protected function onFailure($message)
    {
        return true;
    }
}
