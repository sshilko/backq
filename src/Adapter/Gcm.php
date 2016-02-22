<?php
/**
* GCM Daemon (XMPP CCS)
*
* Copyright (c) 2016, Sergey Shilko (contact@sshilko.com)
*
* @author Sergey Shilko
* @see https://github.com/sshilko/backq
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

    const GCM_HOST      = 'gcm.googleapis.com';
    const GCM_HOST_PORT = 5235;

    const GCM_HOST_DEV = 'gcm-preprod.googleapis.com';
    const GCM_HOST_PORT_DEV = 5236;

    const ERR_CODE_BAD_ACK                      = 'BAD_ACK';
    const ERR_CODE_INVALID_JSON                 = 'INVALID_JSON';
    const ERR_CODE_QUOTA_EXCEEDED               = 'QUOTA_EXCEEDED';
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
     * Event callbacks
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    const EV_ON_CONNECT      = 'on_connect';
    const EV_CONNECT_ERROR   = 'on_connect_error';
    const ON_AUTH_SUCCESS    = 'on_auth_success';
    const ON_AUTH_FAIURE     = 'on_auth_failure';
    const ON_DISCONNECT      = 'on_disconnect';

    /**
     * Message IN
     */
    const ON_NORMAL_MESSAGE = 'on_normal_message';
    const ON_UNDERSCORE_MSG = 'on__message';

    const MSG_ACK  = 'ack';
    const MSG_NACK = 'nack';

    /**
     * Total pushed
     *
     * @var int
     */
    protected $msgSent = 0;

    /**
     * Total got (N)ACK's
     *
     * @var int
     */
    protected $msgAckd = 0;

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
    }

    /**
     * Connect and start
     *
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#constructor-options
     */
    public function connect() {
        if (is_null($this->client)) {
            $c = new \Jaxl(
                array('pass'      => $this->apiKey,
                      'auth_type' => 'PLAIN',
                      'priv_dir'  => sys_get_temp_dir(),
                      'protocol'  => 'ssl',
                      'strict'    => false,
                      'force_tls' => $this->forceTLS,
                      'log_level' => $this->logLevel,
                      'jid'       => $this->senderId . '@' . self::GCM_HOST,
                      'host'      => $this->isTest ? self::GCM_HOST_DEV : self::GCM_HOST,
                      'port'      => $this->isTest ? self::GCM_HOST_PORT_DEV : self::GCM_HOST_PORT)
            );
            $this->client = $c;
            $this->registerCallbacks();
            $this->client->start();
        }
    }

    /**
     * Stop and disconnect
     */
    public function disconnect() {
        $this->on_before_stop();
        $this->client->send_end_stream();
        $this->on_after_stop();
    }


    public function on_normal_message($stanza) {
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
        $this->msgAckd++;

        $data = $this->xmppdecode($stanza);

        $messageType   = $data['message_type'];
        $messageId     = $data['message_id'];  //message id which was sent from us
        $from          = $data['from']; //gcm key;

        if (self::MSG_NACK == $messageType) {

            $error            = isset($data['error']) ? $data['error'] : null; //BAD_REGISTRATION
            $errorDescription = isset($data['error_description']) ? $data['error_description'] : null; //Invalid token
            $this->on_sent_error($from, $messageId, $error, $errorDescription);

        } else {
            $this->on_sent_success($from, $messageId, $this->msgAckd, $this->msgSent);
        }
    }

    /**
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    private function registerCallbacks() {
        foreach (array(self::EV_ON_CONNECT,
                       self::EV_CONNECT_ERROR,
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

        $this->sendGcmMessage(array('collapse_key' => $message->getCollapseKey(), // Could be unset
                                    'time_to_live' => $message->getTimeToLive(), //Could be unset
                                    'delay_while_idle' => $message->getDelayWhileIdle(), //Could be unset
                                    'message_id'       => (string) ($messageId ? $messageId : microtime(true)),
                                    'to'   => $message->getTo(true),
                                    'data' => $message->getData())
        );
        $this->msgSent++;
    }

    protected function sendGcmMessage($payload) {
        $message = '<message id=""><gcm xmlns="google:mobile:data">' . json_encode($payload) . '</gcm></message>';
        $this->client->send_raw($message);
    }

    protected function xmppdecode(\XMPPStanza $stanza) {
        $data = json_decode(html_entity_decode($stanza->childrens[0]->text));
        return $data;
    }

    /**
     * Connected
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    public function on_connect()         {}
    public function on_connect_error()   {}

    /**
     * Disconnected
     * @see http://jaxl.readthedocs.org/en/latest/users/jaxl_instance.html#available-event-callbacks
     */
    public function on_disconnect()      {}

    public function on_auth_failure($reason) {
        $this->disconnect();
    }

    public function on_before_stop() {}
    public function on_after_stop() {}

    /**
     * Ready to send/receive
     */
    public function on_auth_success() {}

    /**
     * On successful ACK for single message
     *
     * @param $from
     * @param $messageId
     * @param $messagesAcked
     * @param $messagesSent
     */
    public function on_sent_success($from, $messageId, $messagesAcked, $messagesSent) {

    }

    /**
     * On NACK for single message (failed to send)
     *
     * @param $from
     * @param $messageId
     * @param $errorCode see ERRO_CODE_* constants
     * @param $errorDescription optional description text
     */
    public function on_sent_error($from, $messageId, $errorCode, $errorDescription = null) {

    }
}