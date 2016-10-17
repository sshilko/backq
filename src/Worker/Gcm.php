<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Sergei Shilko <contact@sshilko.com>
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

namespace BackQ\Worker;

use BackQ\Message\GCMMessage;
use RuntimeException;

final class Gcm extends AbstractWorker
{
    /**
     * This is "Server" Api Key that your project owns
     * @see http://www.androiddocs.com/google/gcm/gs.html
     * @see http://www.androiddocs.com/google/gcm/ccs.html#auth
     */
    private $apiKey;

    /**
     * This is project ID in google console
     * @see http://www.androiddocs.com/google/gcm/gs.html
     * @see http://www.androiddocs.com/google/gcm/ccs.html#auth
     */
    private $senderId;

    private $debugLevel;

    private $environment;
    private $queueName = 'gcmccs';

    const ENVIRONMENT_SANDBOX = true;
    const ENVIRONMENT_PRODUCT = false;

    /**
     * Quit after processing X amount of pushes
     *
     * @var int
     */
    private $restartThreshold = 0;

    /**
     * Quit if inactive for specified time (seconds)
     *
     * @var int
     */
    private $idleTimeout = 0;

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
     * Quit after processing X amount of pushes
     *
     * @param $int
     */
    public function setRestartThreshold($int)
    {
        $this->restartThreshold = (int) $int;
    }

    /**
     * Quit after reaching idle timeout
     *
     * @param $int
     */
    public function setIdleTimeout($int)
    {
        $this->idleTimeout = (int) $int;
    }

    /**
     * GCM Server Api Key
     */
    public function setApiKey($key)
    {
        $this->apiKey = $key;
    }

    /**
     * GCM Library debug level
     * @see JAXLLogger
     * @var int
     */
    public function setDebugLevel($level)
    {
        $this->debugLevel = $level;
    }

    /**
     * GCM Project ID
     */
    public function setSenderId($id)
    {
        $this->senderId = $id;
    }

    /**
     * Declare working environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = (bool) $environment;
    }

    public function run()
    {
        $this->debug('started');
        $connected = $this->start();
        if ($connected) {
            $daemon = null;
            try {
                $this->debug('connected to queue');
                $daemon = new \BackQ\Adapter\Gcm($this->senderId,
                                                 $this->apiKey,
                                                 $this->environment,
                                                 $this->debugLevel,
                                                 true);
                $this->debug('gcm daemon initialized');
                $self = $this;

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_AUTH_ERR, function() use ($self) {
                    $self->debug('GCM Authorized error, disconnecting');
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_CONNECT_ERR, function() use ($self) {
                    $self->debug('GCM Connection error');
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_DISCONNECT, function() use ($self) {
                    $self->debug('GCM Disconnected');
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_MSG_SENT_OK, function($recipientId,
                                                                                        $messageId) use ($self) {
                    $self->debug('GCM Message sent ok');
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_MSG_SENT_ERR, function($recipientId,
                                                                                         $messageId,
                                                                                         $errorCode,
                                                                                         $errorDescription) use ($self) {
                    /**
                     * NACK Received
                     * @see https://developers.google.com/cloud-messaging/xmpp-server-ref#table4
                     */
                    $self->debug('GCM Message sent error: ' . $errorDescription);

                    /**
                     * Downstream message error response codes.
                     *
                     * @see https://developers.google.com/cloud-messaging/xmpp-server-ref#table4
                     */
                    switch ($errorCode) {
                        case \BackQ\Adapter\Gcm::ERR_CODE_INVALID_JSON:
                            /**
                             * - Check that the JSON message is properly formatted and contains valid fields
                             *   (for instance, making sure the right data type is passed in).
                             * - Check that the total size of the payload data included in a message does not
                             *   exceed GCM limits: 4096 bytes for most messages
                             * - Check that the payload data does not contain
                             *   a key (such as from, or gcm, or any value prefixed by google) that is used internally by GCM
                             * - Check that the value used in time_to_live is an integer representing
                             *   a duration in seconds between 0 and 2,419,200 (4 weeks).
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_BAD_REGISTRATION:
                            /**
                             * Check the format of the registration token you pass to the server.
                             * Make sure it matches the registration token the client app receives from registering with GCM.
                             * Do not truncate or add additional characters.
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_DEVICE_UNREGISTERED:
                            /**
                             * An existing registration token may cease to be valid
                             * - client app unregisters with GCM
                             * - user uninstalls the application
                             * - registration token expires
                             * - app is updated but the new version is not configured to receive messages
                             *
                             * Remove this registration token from the app server and stop using it
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_DEVICE_MESSAGE_RATE_EXCEEDED:
                            /**
                             * The rate of messages to a particular device is too high.
                             * Reduce the number of messages sent to this device and do not immediately retry sending to this device.
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_BAD_ACK:
                            /**
                             * Check that the 'ack' message is properly formatted before retrying
                             * @see https://developers.google.com/cloud-messaging/xmpp-server-ref#table6
                             */
                            break;
                        case \BackQ\Adapter\Gcm::ERR_CODE_SERVICE_UNAVAILABLE:
                            /**
                             * The server couldn't process the request in time. Retry the same request later:
                             * - The initial retry delay should be set to 1 second
                             *
                             * Senders that cause problems risk being blacklisted.
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_INTERNAL_SERVER_ERROR:
                            /**
                             * The server encountered an error while trying to process the request
                             */
                            break;

                        case \BackQ\Adapter\Gcm::ERR_CODE_CONNECTION_DRAINING:
                            /**
                             * XMPP connection server needs to close down a connection
                             * Retry the message over another XMPP connection
                             */
                            break;
                    }
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_AUTH_OK, function() use ($self, $daemon) {
                    $self->debug('GCM Authorized ok');
                });

                $this->debug('gcm daemon callbacks initialized');
                $daemon->connect();
                $this->debug('gcm daemon connect initialized');

                $work = $self->work(5);
                $self->debug('after init work generator');

                $jobsdone   = 0;
                $lastactive = time();
                $self->debug('waiting for some work');
                foreach ($work as $taskId => $payload) {
                    $self->debug('got some work');

                    if ($self->idleTimeout > 0 && (time() - $lastactive) > $self->idleTimeout) {
                        $self->debug('idle timeout reached, returning job, quitting');
                        $work->send(false);
                        $daemon->disconnect();
                        break;
                    }

                    if (!$taskId || !$payload) {
                        /**
                         * Timeout reached while waiting for task
                         */
                        $self->debug('no payload yet');
                        continue;
                    }

                    $lastactive = time();

                    if ($self->restartThreshold > 0 && ++$jobsdone > $self->restartThreshold) {
                        $self->debug('restart threshold reached, returning job, quitting');
                        $work->send(false);
                        $daemon->disconnect();
                        break;
                    }



                    $message   = @unserialize($payload);
                    $processed = true;

                    /**
                     * GCM CCS allows only 1 recipient per message
                     */
                    if (!($message instanceof GCMMessage) || 1 != $message->getRecipientsNumber()) {
                        $work->send($processed);
                        @error_log('Worker does not support payload of: ' . gettype($message));
                    } else {

                        try {
                            $daemon->send($message);
                            $self->debug('daemon sent message');
                        } catch (\Exception $e) {
                            $self->debug('generic exception: ' . $e->getMessage());
                            $processed = $e->getMessage();
                            @error_log($e->getMessage());
                        } finally {
                            $work->send((true === $processed));
                        }

                        if (true !== $processed) {
                            /**
                             * Worker not reliable, quitting
                             */
                            throw new \RuntimeException('Worker not reliable, failed to process GCM task: ' . $processed);
                        }
                    }
                };

            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] gcm worker exception: ' . $e->getMessage());
            } finally {
                if ($daemon) {
                    $daemon->disconnect();
                }
            }
        }
        $this->finish();
    }
}
