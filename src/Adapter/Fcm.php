<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

class Fcm extends \Zend_Mobile_Push_Gcm
{
    public $connectTimeout = 2;
    public $actionTimeout  = 3;
    public $maxredirects   = 2;

    /**
     * @see https://developers.google.com/cloud-messaging/http#auth
     * @see https://developers.google.com/cloud-messaging/server#role
     * @see https://firebase.google.com/docs/cloud-messaging/server
     */
    const SERVER_URI = 'https://fcm.googleapis.com/fcm/send';

    public function __construct(string $apiKey) {
        $this->setApiKey($apiKey);
    }

    /**
     * Send Message
     * @return Zend_Http_Response
     */
    public function send(\Zend_Mobile_Push_Message_Abstract $message)
    {
        if (!$message->validate()) {
            throw new \Zend_Mobile_Push_Exception('The message is not valid.');
        }

        /**
         * Customize client -->
         */
        $httpadapter = array('adapter' => 'Zend_Http_Client_Adapter_Curl',
                             'timeout' => $this->connectTimeout,
                             'request_timeout' => $this->actionTimeout,
                             'maxredirects'    => $this->maxredirects,
                             /**
                              * Any options except Zend_Http_Client_Adapter_Curl._invalidOverwritableCurlOptions
                              */
                             'curloptions' => array(CURLOPT_TIMEOUT       => $this->actionTimeout,
                                                    CURLOPT_IPRESOLVE     => CURL_IPRESOLVE_V4,
                                                    CURLOPT_FRESH_CONNECT => 0,
                                                    CURLOPT_FORBID_REUSE  => 0,
                                                    CURLOPT_NOSIGNAL      => true,
                                                    CURLOPT_NOPROGRESS    => true,
                                                  //CURLOPT_TCP_FASTOPEN  => true
                             ));
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $httpadapter['curloptions'][CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $this->setHttpClient(new \Zend_Http_Client(null, $httpadapter));
        /**
         * Customize client <--
         */

        $this->connect();

        $client = $this->getHttpClient();
        $client->setUri(self::SERVER_URI);
        $client->setHeaders('Authorization', 'key=' . $this->getApiKey());

        $client->setRawData($message->toJson(), 'application/json');
        $response = $client->request('POST');
        $this->close();

//        switch ($response->getStatus())
//        {
//            case 500:
//                require_once 'Zend/Mobile/Push/Exception/ServerUnavailable.php';
//                throw new Zend_Mobile_Push_Exception_ServerUnavailable('The server encountered an internal error, try again');
//                break;
//            case 503:
//                require_once 'Zend/Mobile/Push/Exception/ServerUnavailable.php';
//                throw new Zend_Mobile_Push_Exception_ServerUnavailable('The server was unavailable, check Retry-After header');
//                break;
//            case 401:
//                require_once 'Zend/Mobile/Push/Exception/InvalidAuthToken.php';
//                throw new Zend_Mobile_Push_Exception_InvalidAuthToken('There was an error authenticating the sender account');
//                break;
//            case 400:
//                require_once 'Zend/Mobile/Push/Exception/InvalidPayload.php';
//                throw new Zend_Mobile_Push_Exception_InvalidPayload('The request could not be parsed as JSON or contains invalid fields');
//                break;
//        }
        return $response;
    }
}
