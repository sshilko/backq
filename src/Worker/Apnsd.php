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

use RuntimeException;

final class Apnsd extends AbstractWorker
{
    private $logger;
    private $pem;
    private $caCert;
    private $environment;

    protected $queueName = 'apnsd';

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
     * @var int
     */
    public $socketSelectTimeout = 750000;

    const SENDSPEED_TIMEOUT_SAFE        = 2000000; //2.00sec
    const SENDSPEED_TIMEOUT_RECOMMENDED = 750000;  //0.75sec
    const SENDSPEED_TIMEOUT_FAST        = 500000;  //0.50sec
    const SENDSPEED_TIMEOUT_BURST       = 100000;  //0.10sec
    const SENDSPEED_TIMEOUT_DONTCARE    = 50000;   //0.05sec

    public $readWriteTimeout = 10;

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

                $workTimeout = 15;
                $work = $this->work($workTimeout);
                $this->debug('after init work generator');

                $jobsdone   = 0;
                #$lastactive = time();

                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work: ' . ($payload ? 'yes' : 'no'));

                    #if ($this->idleTimeout > 0 && (time() - $lastactive) > $this->idleTimeout) {
                    #    $this->debug('idle timeout reached, returning job, quitting');
                    #    $work->send(false);
                    #    $push->disconnect();
                    #    break;
                    #}

                    if (!$payload && $workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        continue;
                    }

                    #$lastactive = time();

                    #if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold) {
                    #    $this->debug('restart threshold reached, returning job, quitting');
                    #    $work->send(false);
                    #    $push->disconnect();
                    #    break;
                    #}

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \ApnsPHP_Message) || !$message->getRecipientsNumber()) {
                        /**
                         * Nothing to do + report as a success
                         */
                        $work->send($processed);
                        $this->debug('Worker does not support payload of: ' . gettype($message));
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
                        $this->debug('job added to apns queue');
                        $push->send();
                        $this->debug('job queue pushed to apple');
                    } catch (\ApnsPHP_Message_Exception $longpayload) {
                        $this->debug('bad job payload: ' . $longpayload->getMessage());
                    } catch (\ApnsPHP_Push_Exception $networkIssue) {
                        $this->debug('bad connection network: ' . $networkIssue->getMessage());
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
                                throw new \RuntimeException('Errors should not be empty here: ' . json_encode($errors));
                            } else {
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
                                $this->debug('Closing & reconnecting, received code [' . $statusCode . ']');
                                $push->disconnect();
                                $push->connect();
                            }
                        }
                    } else {
                        /**
                         * Worker not reliable, quitting
                         */
                        throw new \RuntimeException('Worker not reliable, failed to process APNS task: ' . $processed);
                    }
                };
            } catch (\Exception $e) {
                $this->debug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage());
            } finally {
                if ($push) {
                    $push->disconnect();
                }
            }
        }
        $this->finish();
    }
}
