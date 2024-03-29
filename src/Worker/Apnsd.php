<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Worker;

use ApnsPHP_Abstract;
use ApnsPHP_Log_Interface;
use ApnsPHP_Message;
use ApnsPHP_Message_Exception;
use ApnsPHP_Push_Exception;
use BackQ\Adapter\ApnsdPush;
use RuntimeException;
use Throwable;
use function current;
use function date;
use function gettype;
use function json_encode;
use function unserialize;

final class Apnsd extends AbstractWorker
{
    public const SENDSPEED_TIMEOUT_SAFE        = 2000000; //2.00sec
    public const SENDSPEED_TIMEOUT_RECOMMENDED = 750000;  //0.75sec
    public const SENDSPEED_TIMEOUT_FAST        = 500000;  //0.50sec
    public const SENDSPEED_TIMEOUT_BURST       = 100000;  //0.10sec
    public const SENDSPEED_TIMEOUT_DONTCARE    = 50000;

    /**
     * PHP 5.5.23 & 5.6.7 does not honor the stream_set_timeout()
     *
     * Instead it uses the connectionTimeout for operations too
     * @see https://github.com/duccio/ApnsPHP/issues/84
     * @see https://bugs.php.net/bug.php?id=69393
     *
     * For those versions use connectTimeout as low as 0.5
     */
    public $connectTimeout = 4;

    public $workTimeout = 11;

    /**
     * Microseconds
     * 750000 = 0.75 sec
     * Time to wait for stream to be available for read/write
     * Essentially its a time we wait for APNS response message,
     * the exact time is how-fast APNS can respond
     * valid values [ 0.05 seconds ... 2 seconds ]
     *
     * Defaults to 0.75
     * The less we wait for response the faster we send messages, but the
     * more changes that we missed that APNS closed the connection,
     *
     * feof() doesnt detect closed connections immediattely then we might think we
     * successfuly sent X messages until we detect the connection is feof()
     * when feof() is finally detected via
     *
     * \ApnsPHP_Push_Exception "Error (2): fwrite(): SSL: Broken pipe"
     * and whole worker shuts down
     *
     * This will only return the LAST push back into "ready" queue, but we might already
     * sent >1 push in between the APNS disconnected and we detected the feof()
     *
     * The longer we wait the less changes we send message to closed socket, but slower the send rates.
     *
     * Recommended between 50000 and 2000000
     *
     */
    public int $socketSelectTimeout = 750000;

    public $readWriteTimeout = 10;

    protected $queueName = 'apnsd';

    private $pushLogger;

    private $pem;

    private $caCert;

    private $environment;//0.05sec

    /**
     * Declare Logger
     */
    public function setPushLogger(ApnsPHP_Log_Interface $log): void
    {
        $this->pushLogger = $log;
    }

    /**
     * Declare CA Authority certificate
     */
    public function setRootCertificationAuthority($caCert): void
    {
        $this->caCert = $caCert;
    }

    /**
     * Declare working environment
     */
    public function setEnvironment($environment = ApnsPHP_Abstract::ENVIRONMENT_SANDBOX): void
    {
        $this->environment = $environment;
    }

    /**
     * Declare path to SSL certificate
     */
    public function setCertificate($pem): void
    {
        $this->pem = $pem;
    }

    public function run(): void
    {
        $connected = $this->start();
        $this->logDebug('started');
        $push = null;
        if ($connected) {
            try {
                $this->logDebug('connected to queue');
                $push = new ApnsdPush($this->environment, $this->pem);
                if ($this->pushLogger) {
                    $push->setLogger($this->pushLogger);
                }
                $push->setRootCertificationAuthority($this->caCert);

                $push->setConnectTimeout($this->connectTimeout);
                $push->setReadWriteTimeout($this->readWriteTimeout);

                $this->logDebug('ready to connect to ios');

                $push->connect();

                $this->logDebug('ios connected');

                /**
                 * Do NOT retry, will restart worker in case things go south
                 * 1 == no retry
                 */
                $push->setSendRetryTimes(1);

                $push->setConnectRetryTimes(3);
                /**
                 * Even if documentation states its " timeout value is the maximum time that will elapse"
                 * @see http://php.net/manual/en/function.stream-select.php
                 * But in reality it always waits this time before returning (php 5.5.22)
                 */
                $push->setSocketSelectTimeout($this->socketSelectTimeout);

                $work = $this->work();
                $this->logDebug('after init work generator');

                #$jobsdone   = 0;
                #$lastactive = time();

                /**
                 * @phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
                 */
                foreach ($work as $_ => $payload) {
                    $this->logDebug('got some work: ' . ($payload ? 'yes' : 'no'));

                    #if ($this->idleTimeout > 0 && (time() - $lastactive) > $this->idleTimeout) {
                    #    $this->logDebug('idle timeout reached, returning job, quitting');
                    #    $work->send(false);
                    #    $push->disconnect();
                    #    break;
                    #}

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        $work->send(true);

                        continue;
                    }

                    #$lastactive = time();

                    #if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold) {
                    #    $this->logDebug('restart threshold reached, returning job, quitting');
                    #    $work->send(false);
                    #    $push->disconnect();
                    #    break;
                    #}

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof ApnsPHP_Message)) {
                        /**
                         * Nothing to do + report as a success
                         */
                        $work->send($processed);
                        $this->logDebug('Worker does not support payload of: ' . gettype($message));

                        continue;
                    }

                    /**
                     * Empty queue, errors are cleaned automatically in send()
                     */
                    $push->getQueue(true);

                    try {
                        /**
                         * We send 1 message per push
                         */
                        $push->add($message);
                        $this->logDebug('job added to apns queue');
                        $push->send();
                        $this->logDebug('job queue pushed to apple');
                    } catch (ApnsPHP_Message_Exception $longpayload) {
                        $this->logDebug('bad job payload: ' . $longpayload->getMessage());
                    } catch (ApnsPHP_Push_Exception $networkIssue) {
                        $this->logDebug('bad connection network: ' . $networkIssue->getMessage());
                        $processed = $networkIssue->getMessage();
                    } finally {
                        /**
                         * If using Beanstalk and not returned success after TTR time,
                         * the job considered failed and is put back into pool of "ready" jobs
                         */
                        $work->send((true === $processed));
                    }

                    if (true === $processed) {
                        $errors = $push->getErrors(false);
                        if (!empty($errors)) {
                            $err = current($errors);
                            if (empty($err['ERRORS'])) {
                                throw new RuntimeException('Errors should not be empty here: ' . json_encode($errors));
                            }

                            $statusCode = $err['ERRORS'][0]['statusCode'];
                            /**
                             * Doesnt matter what the code is, APNS closes the connection after it,
                             * we should reconnect
                             *
                             * 0  - none
                             * 2  - missing token
                             * 3  - missing topic
                             * 4  - missing payload
                             * 5  - invalid token size
                             * 6  - invalid topic size
                             * 7  - invalid payld size
                             * 8  - invalid token
                             * 10 - shutdown (last message was successfuly sent)
                             * ...
                             */
                            $this->logDebug('Closing & reconnecting, received code [' . $statusCode . ']');
                            $push->disconnect();
                            $push->connect();
                        }
                    } else {
                        /**
                         * Worker not reliable, quitting
                         */
                        throw new RuntimeException('Worker not reliable, failed to process APNS task: ' . $processed);
                    }
                }
            } catch (Throwable $e) {
                $this->logDebug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            } finally {
                if ($push) {
                    $push->disconnect();
                }
            }
        }
        $this->finish();
    }
}
