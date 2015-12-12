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
        $this->_nReadWriteTimeout($seconds);
    }

    /**
     * Disconnects from Apple Push Notifications service server.
     * Does not return anything
     * @IMPORTANT ApnsPHP_Push.disconnect() returned boolean (but never used result);
     * @IMPORTANT may break compatibility
     */
    public function disconnect()
    {
        $this->io->close();
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
        $sErrorResponse = $this->io->read(self::ERROR_RESPONSE_SIZE, true);

        if ($sErrorResponse === false || strlen($sErrorResponse) != self::ERROR_RESPONSE_SIZE) {
            return;
        }
        $aErrorResponse = $this->_parseErrorMessage($sErrorResponse);
        if (!is_array($aErrorResponse) || empty($aErrorResponse)) {
            return;
        }
        if (!isset($aErrorResponse['command'], $aErrorResponse['statusCode'], $aErrorResponse['identifier'])) {
            return;
        }
        if ($aErrorResponse['command'] != self::ERROR_RESPONSE_COMMAND) {
            return;
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
            throw new \ApnsPHP_Push_Exception(
                'No notifications queued to be sent'
            );
        }

        $this->_aErrors = array();
        $nRun = 1;
        while (($nMessages = count($this->_aMessageQueue)) > 0) {
            $this->_log("INFO: Sending messages queue, run #{$nRun}: $nMessages message(s) left in queue.");

            $bError = false;
            foreach($this->_aMessageQueue as $k => &$aMessage) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $message = $aMessage['MESSAGE'];
                $sCustomIdentifier = (string)$message->getCustomIdentifier();
                $sCustomIdentifier = sprintf('[custom identifier: %s]', empty($sCustomIdentifier) ? 'unset' : $sCustomIdentifier);

                $nErrors = 0;
                if (!empty($aMessage['ERRORS'])) {
                    foreach($aMessage['ERRORS'] as $aError) {
                        if ($aError['statusCode'] == 0) {
                            $this->_log("INFO: Message ID {$k} {$sCustomIdentifier} has no error ({$aError['statusCode']}), removing from queue...");
                            $this->_removeMessageFromQueue($k);
                            continue 2;
                        } else if ($aError['statusCode'] > 1 && $aError['statusCode'] <= 8) {
                            $this->_log("WARNING: Message ID {$k} {$sCustomIdentifier} has an unrecoverable error ({$aError['statusCode']}), removing from queue without retrying...");
                            $this->_removeMessageFromQueue($k, true);
                            continue 2;
                        }
                    }
                    if (($nErrors = count($aMessage['ERRORS'])) >= $this->_nSendRetryTimes) {
                        $this->_log(
                            "WARNING: Message ID {$k} {$sCustomIdentifier} has {$nErrors} errors, removing from queue..."
                        );
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
                        'identifier' => $k,
                        'statusCode' => self::STATUS_CODE_INTERNAL_ERROR,
                        'statusMessage' => sprintf('%s (%s)',
                                                   $this->_aErrorResponseMessages[self::STATUS_CODE_INTERNAL_ERROR], $e->getMessage())
                    );
                }
//                if ($nLen !== ($nWritten = (int)@fwrite($this->_hSocket, $aMessage['BINARY_NOTIFICATION']))) {
//                    $aErrorMessage = array(
//                        'identifier' => $k,
//                        'statusCode' => self::STATUS_CODE_INTERNAL_ERROR,
//                        'statusMessage' => sprintf('%s (%d bytes written instead of %d bytes)',
//                                                   $this->_aErrorResponseMessages[self::STATUS_CODE_INTERNAL_ERROR], $nWritten, $nLen
//                        )
//                    );
//                }

                $bError = $this->_updateQueue($aErrorMessage);
                if ($bError) {
                    break;
                }
            }

            if (!$bError) {
//                $read = array($this->_hSocket);
//                $null = NULL;
//                $nChangedStreams = @stream_select($read, $null, $null, 0, $this->_nSocketSelectTimeout);

                $nChangedStreams = $this->io->select(0, $this->_nSocketSelectTimeout);
                if ($nChangedStreams === false) {
                    $this->_log('ERROR: Unable to wait for a stream availability.');
                    break;
                } else if ($nChangedStreams > 0) {
                    $bError = $this->_updateQueue();
                    if (!$bError) {
                        $this->_aMessageQueue = array();
                    }
                } else {
                    $this->_aMessageQueue = array();
                }
            }

            $nRun++;
        }
    }

}
