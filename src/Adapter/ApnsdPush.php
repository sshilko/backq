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
namespace BackQ\Adapter;

use Exception;
use RuntimeException;

/**
 * ApnsdPush adapter
 *
 * @see https://github.com/duccio/ApnsPHP
 * @see https://code.google.com/p/apns-php/
 */
class ApnsdPush extends \ApnsPHP_Push
{
    /*
        October 22, 2014
        The Apple Push Notification service will be updated and changes to your servers may be required to remain compatible.
        In order to protect our users against a recently discovered security issue with SSL version 3.0 the Apple Push Notification server
        will remove support for SSL 3.0 on Wednesday, October 29.

        Providers using only SSL 3.0 will need to support TLS as soon as possible to ensure the Apple Push Notification service
        continues to perform as expected. Providers that support both TLS and SSL 3.0 will not be affected and require no changes.

        To check for compatibility, we have already disabled SSL 3.0 on the Provider Communication interface
        in the development environment only.
        Developers can immediately test in this development environment to make sure push notifications can be sent to applications.
    */

    /**
     * Apple deprecated SSL support, switch to TLS
     * @see https://developer.apple.com/news/?id=10222014a
     * @var array
     */
    private $_serviceURLs = array(
        array('gateway.push.apple.com', '2195'),
        array('gateway.sandbox.push.apple.com', '2195')
    );

    private $io;

    /**
     * stream_set_timeout (affects writing operations ?blocking-mode-only?)
     *
     * @var null
     */
    private $_nReadWriteTimeout = null;

    protected function _connect()
    {
        list ($shost, $sport) = $this->_serviceURLs[$this->_nEnvironment];
        try {
            $streamContext = stream_context_create(array('ssl' => array('verify_peer' => isset($this->_sRootCertificationAuthorityFile),
                                                                        'cafile'      => $this->_sRootCertificationAuthorityFile,
                                                                        'local_cert'  => $this->_sProviderCertificateFile)));
            /**
             * @todo customize
             */
            $this->io = new IO\StreamIO($shost, $sport, $this->_nConnectTimeout, $this->_nReadWriteTimeout, $streamContext);
        } catch (\Exception $e) {
            throw new \ApnsPHP_Exception("Unable to connect: " . $e->getMessage());
        }
        return true;
    }

    /**
     * Desired timeout for readwrite operations with stream/socket
     *
     * @param $seconds
     */
    public function setReadWriteTimeout($seconds) {
        $this->_nReadWriteTimeout = $seconds;
    }

    /**
     * Disconnects from Apple Push Notifications service server.
     * Does not return anything
     * @IMPORTANT ApnsPHP_Push.disconnect() returned boolean (but never used result);
     * @IMPORTANT may break compatibility
     */
    public function disconnect()
    {
        if ($this->io) {
            $this->io->close();
        }
    }

    /**
     * Reads an error message (if present) from the main stream.
     * If the error message is present and valid the error message is returned,
     * otherwhise null is returned.
     *
     * @return @type array|null Return the error message array.
     */
    protected function _readErrorMessage()
    {
        $sErrorResponse = $this->io->read(self::ERROR_RESPONSE_SIZE);

        if (!$sErrorResponse) {
            return null;
        }

        if (strlen($sErrorResponse) != self::ERROR_RESPONSE_SIZE) {
            error_log('Read unexpected error response data: ' . $sErrorResponse);
            return null;
        }

        $aErrorResponse = unpack('Ccommand/CstatusCode/Nidentifier', $sErrorResponse);

        if (empty($aErrorResponse)) {
            return null;
        }

        if (!isset($aErrorResponse['command'],
                   $aErrorResponse['statusCode'],
                   $aErrorResponse['identifier'])
            || $aErrorResponse['command'] != self::ERROR_RESPONSE_COMMAND) {
            throw new \BackQ\Adapter\IO\Exception\RuntimeException('Unpacked error response has unexpected format: ' . json_encode($aErrorResponse));
        }

        $aErrorResponse['time'] = time();
        $aErrorResponse['statusMessage'] = 'None (unknown)';

        if (isset($this->_aErrorResponseMessages[$aErrorResponse['statusCode']])) {
            $aErrorResponse['statusMessage'] = $this->_aErrorResponseMessages[$aErrorResponse['statusCode']];
        }

        return $aErrorResponse;
    }

    /**
     * Sends all messages in the message queue to Apple Push Notification Service.
     *
     * @throws ApnsPHP_Push_Exception if not connected to the
     *         service or no notification queued.
     */
    public function send()
    {
        if (empty($this->_aMessageQueue)) {
            throw new \ApnsPHP_Push_Exception('No notifications queued to be sent');
        }

        $this->_aErrors = array();
        $nRun = 1;

        while (($nMessages = count($this->_aMessageQueue)) > 0) {
            $this->_log("INFO: Sending messages queue, run #{$nRun}: $nMessages message(s) left in queue.");

            foreach($this->_aMessageQueue as $k => &$aMessage) {

                if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }

                $message = $aMessage['MESSAGE'];

                $sCustomIdentifier = (string) $message->getCustomIdentifier();
                $sCustomIdentifier = sprintf('[custom identifier: %s]', empty($sCustomIdentifier) ? 'unset' : $sCustomIdentifier);

                $nErrors = 0;
                if (!empty($aMessage['ERRORS'])) {

                    foreach($aMessage['ERRORS'] as $aError) {
                        switch ($aError['statusCode']) {

                            case 0: //No errors encountered
                                $this->_log("INFO: Message ID {$k} {$sCustomIdentifier} has no error ({$aError['statusCode']}), removing from queue...");
                                $this->_removeMessageFromQueue($k);
                                continue 3;
                                break;
                            case 1: //Processing error
                            case 2: //Missing device token
                            case 3: //Missing topic
                            case 4: //Missing payload
                            case 5: //Invalid token size
                            case 6: //Invalid topic size
                            case 7: //Invalid payload size
                            case 8:   //Invalid token
                            case 10:  //Shutdown
                            case 128: //Protocol error (APNs could not parse the notification)
                            case 255: //Unknown error
                            case self::STATUS_CODE_INTERNAL_ERROR:
                                $this->_log("WARNING: Message ID {$k} {$sCustomIdentifier} has an unrecoverable error ({$aError['statusCode']}), removing from queue without retrying...");
                                $this->_removeMessageFromQueue($k, true);
                                continue 3;

                                break;
                        }
                    }

                    if (($nErrors = count($aMessage['ERRORS'])) >= $this->_nSendRetryTimes) {
                        $this->_log("WARNING: Message ID {$k} {$sCustomIdentifier} has {$nErrors} errors, removing from queue...");
                        $this->_removeMessageFromQueue($k, true);
                        continue;
                    }
                }

                $nLen = strlen($aMessage['BINARY_NOTIFICATION']);
                $this->_log("STATUS: Sending message ID {$k} {$sCustomIdentifier} (" . ($nErrors + 1) . "/{$this->_nSendRetryTimes}): {$nLen} bytes.");

                $aErrorMessage = null;
                try {
                    $this->io->write($aMessage['BINARY_NOTIFICATION']);
                } catch (\Exception $e) {
                    $aErrorMessage = array(
                        'identifier'    => $k,
                        'statusCode'    => self::STATUS_CODE_INTERNAL_ERROR,
                        'statusMessage' => sprintf('%s (%s)',
                                                   $this->_aErrorResponseMessages[self::STATUS_CODE_INTERNAL_ERROR],
                                                   $e->getMessage())
                    );
                    $aMessage['ERRORS'][] = $aErrorMessage;
                }
            }

            $nChangedStreams = $this->io->select(0, $this->_nSocketSelectTimeout);

            if (false === $nChangedStreams) {
                $this->_log('ERROR: Unable to wait for a stream availability.');
                throw new \ApnsPHP_Push_Exception('Failed to select io stream for reading');
            } else {
                if (0 === $nChangedStreams) {
                    /**
                     * timeout: expires before anything interesting happened
                     * After successful publish nothing is expected in response
                     */
                    //throw new \ApnsPHP_Push_Exception('Timed out while waiting for io stream become available for reading');
                    $this->_aMessageQueue = array();
                } elseif ($nChangedStreams > 0) {
                    /**
                     * Read the error message (or nothing) from stream and update the queue/cycle
                     */
                    if (!$this->_updateQueue()) {
                        $this->_aMessageQueue = array();
                    }
                }
            }

            $nRun++;
        }
    }

}
