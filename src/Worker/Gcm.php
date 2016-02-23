<?php
/**
* BackQ
*
* Copyright (c) 2016, Sergey Shilko (contact@sshilko.com)
*
* @author Sergey Shilko
* @see https://github.com/sshilko/backq
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
     * GCM Server Api Key
     */
    public function setApiKey($key)
    {
        $this->apiKey = $key;
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
        $connected = $this->start();
        $this->debug('started');
        if ($connected) {
            $daemon = null;
            try {
                $this->debug('connected to queue');
                $daemon = new \BackQ\Adapter\Gcm($this->senderId,
                                                 $this->apiKey,
                                                 $this->environment,
                                                 \BackQ\Adapter\Gcm::LOG_INFO,
                                                 true);

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_AUTH_OK, function() use ($daemon) {
                    echo 'Authorized';

                    $message = new GCMMessage("c9IlA3Rkuo8:APA91bFV8LuBqVl2N_v5TlEbyCT8CqpPOBMbry9QlptFp460n72C8hfSurWdXehOlSSJ6IhIvysFhjkL1wVsoa2QJcySvDYbRHyNHdc59sFfadTte_4GQbQEsukJ65XHYWcx8_Fm7vgK",
                                              ['text'=> time() . ".message from server"],
                                              "collapse-key" . time());
                    $daemon->send($message);

                });
                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_AUTH_ERR, function() {
                    echo 'Not authorized';
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_CONNECT_ERR, function() {
                    echo 'Connection error';
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_DISCONNECT, function() {
                    echo 'Disconnected';
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_MSG_SENT_OK, function() {
                    echo 'Message SENT';
                });

                $daemon->setCallback(\BackQ\Adapter\Gcm::CALLBACK_MSG_SENT_ERR, function() {
                    echo 'Message NOT SENT';
                });

                $daemon->connect();

                $work = $this->work();
                $this->debug('after init work generator');

                $jobsdone   = 0;
                $lastactive = time();
                foreach ($work as $taskId => $payload) {
                    $this->debug('got some work');

                    if ($this->idleTimeout > 0 && (time() - $lastactive) > $this->idleTimeout) {
                        $this->debug('idle timeout reached, returning job, quitting');
                        $work->send(false);
                        $daemon->disconnect();
                        break;
                    }

                    $lastactive = time();

                    if ($this->restartThreshold > 0 && ++$jobsdone > $this->restartThreshold) {
                        $this->debug('restart threshold reached, returning job, quitting');
                        $work->send(false);
                        $daemon->disconnect();
                        break;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    if (!($message instanceof \BackQ\Message\GCMMessage) || !$message->getRecipientsNumber()) {
                        $work->send($processed);
                        @error_log('Worker does not support payload of: ' . gettype($message));
                    } else {

                        try {
                            $daemon->send($message);
                            $this->debug('daemon sent message');
                        } catch (\Exception $e) {
                            $this->debug('generic exception: ' . $e->getMessage());
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
