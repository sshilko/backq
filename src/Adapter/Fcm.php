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
