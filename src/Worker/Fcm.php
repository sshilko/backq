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

namespace BackQ\Worker;

final class Fcm extends AbstractWorker
{
    protected $queueName = 'fcm';

    /**
     * @var \Zend_Mobile_Push_Gcm
     */
    protected $pusher = null;

    public $workTimeout = 4;

    public function setPusher(\Zend_Mobile_Push_Gcm $pusher) {
        $this->pusher = $pusher;
    }

    public function run()
    {
        $connected = $this->start();
        $this->debug('started');
        $push = null;
        if ($connected) {
            try {
                $this->debug('connected to queue');

                $work = $this->work($this->workTimeout);
                $this->debug('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work: ' . ($payload ? 'yes' : 'no'));

                    if (!$payload && $this->workTimeout > 0) {
                        continue;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \Zend_Mobile_Push_Message_Gcm)) {
                        /**
                         * Nothing to do + report as a success
                         */
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
                        continue;
                    }
                    try {

                        /**
                         * returns Zend_Http_Response
                         * @see https://developers.google.com/cloud-messaging/http#message-with-payload--notification-message
                         * @see https://developers.google.com/cloud-messaging/http-server-ref#interpret-downstream
                         */
                        $zhr     = $this->pusher->send($message);
                        $status  = $zhr->getStatus();
                        $body    = @json_decode($zhr->getBody());
                        $this->debug('Response body: ' . json_encode($body));

                        $stokens = $message->getToken();
                        if ($status >= 500 && $status <= 599) {

                            /**
                             * Errors in the 500-599 range (such as 500 or 503) indicate that there was an internal error
                             * in the GCM connection server while trying to process the request,
                             * or that the server is temporarily unavailable
                             *
                             * * Unavailable
                             * * InternalServerError
                             */
                            $processed = false;
                        }

                        $updatedTokens = [];
                        $uninstalls    = [];

                        switch ($status) {
                            /**
                             * JSON request is successful (HTTP status code 200)
                             * Message was processed successfully. The response body will contain more details
                             */
                            case 200:
                                if (!$body) {
                                    $processed = false;
                                }

                                if (!$body->canonical_ids && !$body->failure) {
                                    /**
                                     * If the value of failure and canonical_ids is 0, it's not necessary to parse the remainder of the response
                                     */
                                } else {
                                    if (is_array($body->results)) {
                                        $brs = $body->results;
                                        for ($i = 0;$i < count($body->results); $i++) {
                                            $br = $brs[$i];
                                            /**
                                             * @see https://developers.google.com/cloud-messaging/http-server-ref#table5
                                             */
                                            if ($br->message_id) {
                                                if ($br->registration_id) {
                                                    /**
                                                     * replace the original ID with the new value (canonical ID) in your server database
                                                     */
                                                    $updatedTokens[$stokens[$i]] = $br->registration_id;
                                                } else {
                                                    /**
                                                     * all OK
                                                     */
                                                }
                                            } else {
                                                if ($br->error) {
                                                    switch ($br->error) {
                                                        case 'TopicsMessageRateExceeded':
                                                            /**
                                                             * The rate of messages to subscribers to a particular topic is too high.
                                                             * Reduce the number of messages sent for this topic, and do not immediately retry sending.
                                                             */
                                                            $processed = $br->error;
                                                            break;

                                                        case 'DeviceMessageRateExceeded':
                                                            /**
                                                             * The rate of messages to a particular device is too high.
                                                             * Reduce the number of messages sent to this device and
                                                             * do not immediately retry sending to this device.
                                                             */
                                                            $processed = $br->error;
                                                            break;

                                                        case 'InternalServerError':
                                                            /**
                                                             * The server encountered an error while trying to process the request.
                                                             * You could retry the same request following the requirements listed in "Timeout"
                                                             */
                                                            $processed = $br->error;
                                                            break;

                                                        case 'Unavailable':
                                                            /**
                                                             * The server couldn't process the request in time. Retry the same request
                                                             * + Honor the Retry-After header if it is included in the response from the GCM
                                                             * + Implement exponential back-off in your retry mechanism
                                                             */
                                                            break;

                                                        case 'InvalidTtl':
                                                            /**
                                                             * Check that the value used in time_to_live is an integer
                                                             * representing a duration in seconds between 0 and 2,419,200 (4 weeks).
                                                             */
                                                            break;

                                                        case 'InvalidDataKey':
                                                            /**
                                                             * Check that the payload data does not contain a key
                                                             * (such as from, or gcm, or any value prefixed by google)
                                                             * that is used internally by GCM. Note that some words
                                                             * (such as collapse_key) are also used by GCM but are allowed
                                                             * in the payload, in which case the payload value will be
                                                             * overridden by the GCM value.
                                                             */
                                                            break;

                                                        case 'MessageTooBig':
                                                            /**
                                                             * Check that the total size of the payload data included in a message
                                                             * does not exceed GCM limits: 4096 bytes for most messages,
                                                             * or 2048 bytes in the case of messages to topics or notification
                                                             * messages on iOS. This includes both the keys and the values.
                                                             */
                                                            break;

                                                        case 'MismatchSenderId':
                                                            /**
                                                             * A registration token is tied to a certain group of senders.
                                                             * When a client app registers for GCM, it must specify which senders
                                                             * are allowed to send messages. You should use one of those sender
                                                             * IDs when sending messages to the client app.
                                                             * If you switch to a different sender, the existing registration tokens won't work.
                                                             */
                                                            break;

                                                        case 'InvalidPackageName':
                                                            /**
                                                             * Make sure the message was addressed to a registration token
                                                             * whose package name matches the value passed in the request.
                                                             */
                                                            break;

                                                        case 'InvalidRegistration':
                                                            /**
                                                             * Check the format of the registration token you pass to the server.
                                                             * Make sure it matches the registration token the client app receives
                                                             * from registering with GCM. Do not truncate or add additional characters.
                                                             */
                                                            $uninstalls[] = $stokens[$i];
                                                            break;

                                                        case 'MissingRegistration':
                                                            /**
                                                             * Check that the request contains a registration token
                                                             * (in the registration_id in a plain text message,
                                                             * or in the to or registration_ids field in JSON).
                                                             */
                                                            break;

                                                        case 'NotRegistered':
                                                            /**
                                                             * should remove the registration ID from your server database
                                                             * because the application was uninstalled from the device,
                                                             * or the client app isn't configured to receive messages
                                                             */
                                                            $uninstalls[] = $stokens[$i];
                                                            break;

                                                        default:
                                                            /**
                                                             * Otherwise, there is something wrong in the registration token passed
                                                             * in the request; it is probably a non-recoverable error that will
                                                             * also require removing the registration from the server database.
                                                             * @see https://developers.google.com/cloud-messaging/http#example-responses
                                                             */
                                                            break;
                                                    }
                                                } else {
                                                    throw new \RuntimeException('Received unexpected results body in FCM for message, missing error & message_id: ' . json_encode($br));
                                                }
                                            }
                                        }
                                    } else {
                                        throw new \RuntimeException('Received unexpected results body from FCM: ' . json_encode($body));
                                    }
                                }
                                break;
                            case 400:
                                /**
                                 * Only applies for JSON requests.
                                 * Indicates that the request could not be parsed as JSON,
                                 * or it contained invalid fields (for instance, passing a string where a number was expected).
                                 */
                                break;
                            case 401:
                                /**
                                 * There was an error authenticating the sender account.
                                 * + Authorization header missing or with invalid syntax in HTTP request.
                                 * + Invalid project number sent as key.
                                 * + Key valid but with GCM service disabled.
                                 * + Request originated from a server not whitelisted in the Server Key IPs.
                                 */
                                break;
                            default:
                                error_log('Unexpected HTTP status code from FCM: ' . $status);
                                break;
                        }
                        /**
                         * $updatedTokens is list of tokens that changed
                         * $uninstalls    is list of no longer registered tokens
                         */
                    } catch (\Exception $e) {
                        error_log('Error while sending FCM: ' . $e->getMessage());
                    } finally {
                        /**
                         * If using Beanstalk and not returned success after TTR time,
                         * the job considered failed and is put back into pool of "ready" jobs
                         */
                        $work->send((true === $processed));
                    }
                }
            } catch (\Exception $e) {
                $this->debug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            }
        }
        $this->finish();
    }
}
