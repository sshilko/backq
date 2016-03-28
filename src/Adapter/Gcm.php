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

namespace BackQ\Adapter;

use BackQ\Message\GCMMessage;
use BackQ\Adapter\Gcm\RecievedMessage;

/**
 * GCM CCS Daemon (XMPP)
 *
 * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
 * @see https://packagist.org/packages/abhinavsingh/jaxl
 * @see https://developers.google.com/android/reference/com/google/android/gms/gcm/GoogleCloudMessaging
 * @see https://developers.google.com/cloud-messaging/ccs#response
 */
class Gcm
{
    private $client = null;

    private $senderId;
    private $apiKey;
    private $isTest;
    private $logLevel;
    private $forceTLS;

    private $hostname;
    private $mypid;

    const GCM_HOST      = 'gcm.googleapis.com';
    const GCM_HOST_PORT = 5235;

    const GCM_HOST_DEV = 'gcm-preprod.googleapis.com';
    const GCM_HOST_PORT_DEV = 5236;

    /**
     * COMPLETE LIST of ERROR_CODE's
     * @see https://developers.google.com/cloud-messaging/xmpp-server-ref#table4
     */

    /**
     * Check that the 'ack' message is properly formatted before retrying
     */
    const ERR_CODE_BAD_ACK                      = 'BAD_ACK';

    /**
     * InvalidJson: JSON_TYPE_ERROR : Field \"time_to_live\" must be a JSON java.lang.Number: abc
     */
    const ERR_CODE_INVALID_JSON                 = 'INVALID_JSON';

    const ERR_CODE_JSON_TYPE_ERROR              = 'JSON_TYPE_ERROR';
    const ERR_CODE_QUOTA_EXCEEDED               = 'QUOTA_EXCEEDED';

    /**
     * Check the format of the registration token you pass to the server.
     * Make sure it matches the registration token the client app receives from registering with GCM
     */
    const ERR_CODE_BAD_REGISTRATION             = 'BAD_REGISTRATION';

    const ERR_CODE_CONNECTION_DRAINING          = 'CONNECTION_DRAINING';
    const ERR_CODE_DEVICE_UNREGISTERED          = 'DEVICE_UNREGISTERED';
    const ERR_CODE_SERVICE_UNAVAILABLE          = 'SERVICE_UNAVAILABLE';
    const ERR_CODE_INTERNAL_SERVER_ERROR        = 'INTERNAL_SERVER_ERROR';
    const ERR_CODE_DEVICE_MESSAGE_RATE_EXCEEDED = 'DEVICE_MESSAGE_RATE_EXCEEDED';

    /**
     * @see JAXLLogger
     */
    const LOG_ERROR  = JAXL_ERROR;
    const LOG_WARN   = JAXL_WARNING;
    const LOG_NOTICE = JAXL_NOTICE;
    const LOG_INFO   = JAXL_INFO;
    const LOG_DEBUG  = JAXL_DEBUG;

    /**
     * Should NOT bind to on_connect, breaks auth flow
     */
    //const ON_CONNECT         = 'on_connect';

    /**
     * Event callbacks
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    const ON_CONNECT_ERROR   = 'on_connect_error';
    const ON_AUTH_SUCCESS    = 'on_auth_success';
    const ON_AUTH_FAIURE     = 'on_auth_failure';
    const ON_DISCONNECT      = 'on_disconnect';
    const ON_STREAM_FEA      = 'on_stream_features';

    /**
     * Message received
     */
    const ON_NORMAL_MESSAGE = 'on_normal_message';

    /**
     * (N)ACK Message received
     */
    const ON_UNDERSCORE_MSG = 'on__message';

    const MSG_ACK  = 'ack';
    const MSG_NACK = 'nack';

    /**
     * Callbacks -->
     */
    const CALLBACK_CONNECT_ERR  = 'on_connect_error';
    const CALLBACK_AUTH_OK      = 'on_auth_success';
    const CALLBACK_AUTH_ERR     = 'on_auth_failure';
    const CALLBACK_DISCONNECT   = 'on_disconnect';
    const CALLBACK_MSG_SENT_OK  = 'on_sent_success';
    const CALLBACK_MSG_SENT_ERR = 'on_sent_error';
    /**
     * Callbacks <--
     */

    /**
     * Total pushed
     *
     * @var int
     */
    //protected $msgSent = 0;

    /**
     * Total got (N)ACK's
     *
     * @var int
     */
    //protected $msgAckd = 0;

    protected $callbacks = array();

    /**
     * Gcm constructor.
     *
     * @param      $senderId
     * @param      $apiKey
     * @param bool $isTest
     * @param int  $logLevel
     * @param bool $forceTLS
     */
    public function __construct($senderId, $apiKey, $isTest = false, $logLevel = self::LOG_NOTICE, $forceTLS = true) {
        $this->senderId = $senderId;
        $this->apiKey   = $apiKey;
        $this->isTest   = $isTest;
        $this->logLevel = $logLevel;
        $this->forceTLS = $forceTLS;

        $this->hostname = gethostname();
        $this->mypid    = getmypid();
    }

    public function setCallback($event, callable $callback) {
        $this->callbacks[$event] = $callback;
    }

    protected function getCallback($event) {
        if (isset($this->callbacks[$event]) && is_callable($this->callbacks[$event])) {
            return $this->callbacks[$event];
        }
    }

    /**
     * Connect and start
     *
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#constructor-options
     */
    public function connect() {
        if (is_null($this->client)) {
            $c = new Gcm\Jaxl(
                array('pass'      => $this->apiKey,
                      'auth_type' => 'PLAIN',
                      'priv_dir'  => sys_get_temp_dir(),
                      'protocol'  => 'tls',
                      'strict'    => false,
                      'stream_context' => stream_context_create(array('ssl' => array('verify_peer' => true))),
                      'force_tls' => $this->forceTLS,
                      'log_level' => $this->logLevel,
                      'jid'       => $this->senderId . '@' . self::GCM_HOST,
                      'host'      => $this->isTest ? self::GCM_HOST_DEV : self::GCM_HOST,
                      'port'      => $this->isTest ? self::GCM_HOST_PORT_DEV : self::GCM_HOST_PORT)
            );
            $this->client = $c;
            $this->registerCallbacks();
            //$this->client->start(array('--with-unix-sock' => true));
            $this->client->start();
        }
    }

    /**
     * Stop and disconnect
     */
    public function disconnect() {
        $this->client->send_end_stream();
    }

    protected function on_normal_message($stanza) {
        $data = $this->xmppdecode($stanza);

        $message = new RecievedMessage($data['category'],
                                       $data['data'],
                                       $data['time_to_live'],
                                       $data['message_id'],
                                       $data['from']);

        $this->sendGcmMessage(array('message_type' => self::MSG_ACK,
                                    'to'           => $message->getFrom(),
                                    'message_id'   => $message->getMessageId()));
        return $message;
    }

    public function on__message($stanza) {
        //$this->msgAckd++;

        $data = $this->xmppdecode($stanza);

        $messageType   = $data['message_type'];
        $messageId     = $data['message_id'];  //message id which was sent from us
        $from          = $data['from']; //gcm key;

        if (self::MSG_NACK == $messageType) {
            $error            = isset($data['error']) ? $data['error'] : null; //BAD_REGISTRATION
            $errorDescription = isset($data['error_description']) ? $data['error_description'] : null; //Invalid token
            $this->on_sent_error($from, $messageId, $error, $errorDescription);

        } else {
            $this->on_sent_success($from, $messageId);
        }

    }

    /**
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    protected function registerCallbacks() {
        foreach (array(//self::ON_CONNECT,//binding to on_connect breaks auth flow
                       self::ON_CONNECT_ERROR,
                       self::ON_AUTH_SUCCESS,
                       self::ON_AUTH_FAIURE,
                       self::ON_DISCONNECT,
                       self::ON_NORMAL_MESSAGE,
                       self::ON_UNDERSCORE_MSG) as $event) {

            $this->client->add_cb($event, array($this, $event));
        }

    }

    public function send(GCMMessage $message, $messageId = false) {

        if (count($message->getTo()) == 0) {
            throw new \LogicException("No recipients defined");
        }

        if (count($message->getTo()) > 1) {
            throw new \LogicException("XMPP CCS Supports 1 recipient per message");
        }

        if (empty($messageId)) {
            $messageId = md5(microtime() . $this->hostname . $this->mypid);
        }

        $this->sendGcmMessage(array('collapse_key' => $message->getCollapseKey(), // Could be unset
                                    'time_to_live' => $message->getTimeToLive(), //Could be unset
                                    'delay_while_idle' => $message->getDelayWhileIdle(), //Could be unset
                                    'message_id'       => $messageId,
                                    'to'   => $message->getTo(true),
                                    'data' => $message->getData())
        );
        //$this->msgSent++;
    }

    protected function sendGcmMessage($payload) {
        $message = '<message id=""><gcm xmlns="google:mobile:data">' . json_encode($payload) . '</gcm></message>';
        $this->client->send_raw($message);
    }

    protected function xmppdecode(\XMPPStanza $stanza) {
        $data = json_decode(html_entity_decode($stanza->childrens[0]->text), true);
        return $data;
    }

    public function on_connect_error() {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, array());
        }
    }

    /**
     * Disconnected
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    public function on_disconnect() {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, array());
        }
    }

    public function on_auth_failure($reason) {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, array($reason));
        }
        $this->disconnect();
    }

    /**
     * Ready to send/receive
     */
    public function on_auth_success() {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, array());
        }
    }

    public function on_stream_features() {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, array());
        }
    }

    /**
     * On successful ACK for single message
     *
     * @param $from
     * @param $messageId
     */
    protected function on_sent_success($recipientId, $messageId) {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, func_get_args());
        }
    }

    /**
     * On NACK for single message (failed to send)
     *
     * @param $from
     * @param $messageId
     * @param $errorCode see ERRO_CODE_* constants
     * @param $errorDescription optional description text
     */
    protected function on_sent_error($recipientId, $messageId, $errorCode, $errorDescription = null) {
        if ($cb = $this->getCallback(__FUNCTION__)) {
            call_user_func_array($cb, func_get_args());
        }
    }
}