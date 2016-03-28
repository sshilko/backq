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
    public $socketSelectTimeout = 750000;
    public $readWriteTimeout = 10;

    /**
     * Check whether worker code changed in runtime,
     * since production has opcache most of the time enabled
     * will not work; disabling by default
     */
    public $quitIfModified = false;

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
     * Error codes that require restarting the apns connection
     *
     * A status code of 10 indicates that the APNs server closed the connection (for example, to perform maintenance).
     * The notification identifier in the error response indicates the last notification that was successfully sent.
     * Any notifications you sent after it have been discarded and must be resent.
     * When you receive this status code, stop using this connection and open a new connection.
     *
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/CommunicatingWIthAPS.html
     */
    const CODE_APNS_PARSEERR = 128;
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
        $push = null;
        if ($connected) {
            try {
                $this->debug('connected to queue');
                $push = new \BackQ\Adapter\ApnsdPush($this->environment, $this->pem);
                if ($this->logger) {
                    $push->setLogger($this->logger);
                }
                $push->setRootCertificationAuthority($this->caCert);

                $push->setConnectTimeout($this->connectTimeout);
                $push->setReadWriteTimeout($this->readWriteTimeout);

                $this->debug('ready to connect to ios');

                $push->connect();

                $this->debug('ios connected');

                /**
                 * Do NOT retry, will restart worker in case things go south
                 */
                $push->setSendRetryTimes(1);

                $push->setConnectRetryTimes(3);
                /**
                 * Even if documentation states its " timeout value is the maximum time that will elapse"
                 * @see http://php.net/manual/en/function.stream-select.php
                 * But in reality it always waits this time before returning (php 5.5.22)
                 */
                $push->setSocketSelectTimeout($this->socketSelectTimeout);

                $workTimeout = 30;
                $work = $this->work($workTimeout);
                $this->debug('after init work generator');

                $jobsdone   = 0;
                $lastactive = time();
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work: ' . ($payload ? 'yes' : 'no'));

                    if ($this->idleTimeout > 0 && (time() - $lastactive) > $this->idleTimeout) {
                        $this->debug('idle timeout reached, returning job, quitting');
                        $work->send(false);
                        $push->disconnect();
                        break;
                    }

                    if (!$payload && $workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }

                    $lastactive = time();

                    if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold) {
                        $this->debug('restart threshold reached, returning job, quitting');
                        $work->send(false);
                        $push->disconnect();
                        break;
                    }

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
                    $reconnect = false;

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
                             * We send 1 message per push
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
                            $reconnect = true;
                        } finally {
                            $work->send((true === $processed));
                        }

                        $errors = $push->getErrors(false);

                        if (!empty($errors)) {
                            $err = isset($errors[0]['ERRORS'][0]['statusCode']) ? $errors[0]['ERRORS'][0] : null;
                            if (!$err) {
                                error_log('Unexpected errors: ' . @json_encode($errors));
                                $processed = false;
                            } else {
                                switch ($err['statusCode']) {
                                    /**
                                     * Restart the worker
                                     */
                                    case self::CODE_APNS_PARSEERR:
                                    case self::CODE_APNS_UNKNOWN:
                                        @error_log('apnsd worker error data: ' . @json_encode($err));
                                        $processed = $err['statusCode'] . ' ' . $err['statusMessage'];
                                        break;
                                    default:
                                        /**
                                         * 0  - none
                                         * 2  - missing token
                                         * 3  - missing topic
                                         * 4  - missing payload
                                         * 5  - invalid token size
                                         * 6  - invalid topic size
                                         * 7  - invalid payld size
                                         * 8  - invalid token
                                         * 10 - shutdown (last message was successfuly sent)
                                         *
                                         * Reconnect after APNS response:
                                         * APNs returns an error-response packet and closes the connection
                                         * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Appendixes/BinaryProviderAPI.html#//apple_ref/doc/uid/TP40008194-CH106-SW5
                                         */
                                        $reconnect = true;
                                        break;
                                }
                            }
                        }

                        if (true !== $processed) {
                            /**
                             * Worker not reliable, quitting
                             */
                            throw new \RuntimeException('Worker not reliable, failed to process APNS task: ' . $processed);
                        }

                        if ($reconnect) {
                            $push->disconnect();
                            $push->connect();
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
