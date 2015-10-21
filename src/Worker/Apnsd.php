<?php
/**
* BackQ
*
* Copyright (c) 2014, Sergey Shilko (contact@sshilko.com)
*
* @author Sergey Shilko
* @see https://github.com/sshilko/backq
*
**/
namespace BackQ\Worker;

use RuntimeException;

final class Apnsd extends AbstractWorker
{
    private $logger;
    private $pem;
    private $caCert;
    private $environment;

    private $queueName = 'apnsd';

    /**
     * PHP 5.5.23 & 5.6.7 does not honor the stream_set_timeout()
     *
     * Instead it uses the connectionTimeout for operations too
     * @see https://github.com/duccio/ApnsPHP/issues/84
     * @see https://bugs.php.net/bug.php?id=69393
     *
     * For those versions use connectTimeout as low as 0.5
     */
    public $connectTimeout = 5;
    public $socketSelectTimeout = 500000;

    /**
     * Check whether worker code changed in runtime,
     * since production has opcache most of the time enabled
     * will not work; disabling by default
     */
    public $quitIfModified = false;

    /**
     * Error codes that require restarting the apns connection
     *
     * A status code of 10 indicates that the APNs server closed the connection (for example, to perform maintenance).
     * The notification identifier in the error response indicates the last notification that was successfully sent.
     * Any notifications you sent after it have been discarded and must be resent.
     * When you receive this status code, stop using this connection and open a new connection.
     *
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/CommunicatingWIthAPS.html
     */
    const CODE_APNS_PROCERRN = 1;
    const CODE_APNS_SHUTDOWN = 10;
    const CODE_APNS_UNKNOWN  = 255;

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
     * Set queue this worker is going to use
     *
     * @param $string
     */
    public function setQueueName($string)
    {
        $this->queueName = (string) $string;
    }

    /**
     * Declare Logger
     */
    public function setLogger(\ApnsPHP_Log_Interface $log)
    {
        $this->logger = $log;
    }

    /**
     * Declare CA Authority certificate
     */
    public function setRootCertificationAuthority($caCert)
    {
        $this->caCert = $caCert;
    }

    /**
     * Declare working environment
     */
    public function setEnvironment($environment = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX)
    {
        $this->environment = $environment;
    }

    /**
     * Declare path to SSL certificate
     */
    public function setCertificate($pem)
    {
        $this->pem = $pem;
    }

    public function run()
    {
        $version   = filemtime(__FILE__);
        $connected = $this->start();
        $this->debug('started');
        if ($connected) {
            try {
                $this->debug('connected to queue');
                $push = new \BackQ\Adapter\ApnsdPush($this->environment, $this->pem);
                if ($this->logger) {
                    $push->setLogger($this->logger);
                }
                $push->setRootCertificationAuthority($this->caCert);

                $push->setConnectTimeout($this->connectTimeout);

                $this->debug('ready to connect to ios');

                $push->connect();

                $this->debug('ios connected');

                /**
                 * Dont retry, will restart worker in case things go south
                 */
                $push->setSendRetryTimes(0);

                $push->setConnectRetryTimes(3);
                /**
                 * Even if documentation states its " timeout value is the maximum time that will elapse"
                 * @see http://php.net/manual/en/function.stream-select.php
                 * But in reality it always waits this time before returning (php 5.5.22)
                 */
                $push->setSocketSelectTimeout($this->socketSelectTimeout);

                $work = $this->work();
                $this->debug('after init work generator');
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    if ($this->quitIfModified) {
                        /**
                         * If worker code was modified should exit gracefully
                         */
                        clearstatcache();
                        if ($version != filemtime(__FILE__)) {
                            /**
                             * Return the work, worker version changed, quitting
                             */
                            $this->debug('worker code changed, returning job');
                            $work->send(false);
                            break;
                        }
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \ApnsPHP_Message) || !$message->getRecipientsNumber()) {
                        $work->send($processed);
                        @error_log('Worker does not support payload of: ' . gettype($message));
                    } else {
                        /**
                         * Empty queue & errors before working
                         */
                        $push->getQueue(true);
                        $push->getErrors(true);

                        try {
                            /**
                             * We send 1 message per push, thats easier handling errors (see below error handling section, especially error code 10 from apple)
                             */
                            $push->add($message);
                            $this->debug('job added to apns queue');
                            $push->send();
                            $this->debug('job queue pushed to apple');
                        } catch (\ApnsPHP_Message_Exception $longpayload) {
                            $this->debug('bad job payload');
                            @error_log($longpayload->getMessage());
                        } catch (\ApnsPHP_Push_Exception $networkIssue) {
                            $this->debug('bad connection network');
                            @error_log($networkIssue->getMessage());
                            $processed = $networkIssue->getMessage();
                        } finally {
                            $work->send((true === $processed));
                        }

                        $errors = $push->getErrors(false);

                        if (!empty($errors)) {
                            if (is_array($errors)) {
                                /**
                                 * Sending one message per push will result in one error per push
                                 * Setting setSendRetryTimes to 0 will result in one error (no retries) per message pushed
                                 *
                                 * Also when message 10 arrives from apple, we either check the last successfull message sent and re-try others or just
                                 * send 1 message per push which is easier code-wise
                                 */
                                foreach ($errors as $e) {
                                    if (isset($e['ERRORS']) && is_array($e['ERRORS'])) {
                                        foreach ($e['ERRORS'] as $err) {
                                            if (isset($err['statusCode'])) {
                                                switch ($err['statusCode']) {
                                                    /**
                                                     * Critical errors requiring action
                                                     */
                                                    case \ApnsPHP_Push::STATUS_CODE_INTERNAL_ERROR:
                                                    // no break
                                                    case self::CODE_APNS_PROCERRN:
                                                    // no break
                                                    case self::CODE_APNS_SHUTDOWN:
                                                    // no break
                                                    case self::CODE_APNS_UNKNOWN:
                                                        @error_log('apnsd worker error data: ' . @json_encode($err));
                                                        $processed = $err['statusCode'] . ' ' . $err['statusMessage'];
                                                        break;
                                                    default:
                                                        /**
                                                         * All other cases (no error, bad payload, bad token and all other validation, we dont restart worker and report OK to queue)
                                                         */
                                                        break;
                                                }
                                            }
                                        }
                                    } else {
                                        @error_log('apnsd worker error generic: ' . @json_encode($e));
                                        $processed = false;
                                    }
                                }
                            } else {
                                @error_log('apnsd worker error string: ' . @json_encode($errors));
                                $processed = false;
                            }
                        }

                        if (true !== $processed) {
                            /**
                             * Worker not reliable, quitting
                             */
                            throw new \RuntimeException('Worker not reliable, failed to process APNS task: ' . $processed);
                        }
                    }
                };
            } catch (\Exception $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] apnsd worker exception: ' . $e->getMessage());
            } finally {
                if ($push) {
                    $push->disconnect();
                }
            }
        }
        $this->finish();
    }
}
