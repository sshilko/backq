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

use ApnsPHP_Exception;
use ApnsPHP_Push;
use ApnsPHP_Push_Exception;
use BackQ\Adapter\IO\Exception\RuntimeException;
use Throwable;
use function count;
use function defined;
use function function_exists;
use function json_encode;
use function pcntl_signal_dispatch;
use function stream_context_create;
use function strlen;
use function time;
use function unpack;

/**
 * ApnsdPush adapter
 *
 * @see https://github.com/duccio/ApnsPHP
 * @see https://code.google.com/p/apns-php/
 */
class ApnsdPush extends ApnsPHP_Push
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
    private array $_serviceURLs = [
        ['gateway.push.apple.com', '2195'],
        ['gateway.sandbox.push.apple.com', '2195'],
    ];

    private $io;

    /**
     * stream_set_timeout (affects writing operations ?blocking-mode-only?)
     *
     * @var null
     */
    private $_nReadWriteTimeout = null;

    /**
     * Desired timeout for readwrite operations with stream/socket
     *
     * @param $seconds
     */
    public function setReadWriteTimeout($seconds): void
    {
        $this->_nReadWriteTimeout = $seconds;
    }

    /**
     * Disconnects from Apple Push Notifications service server.
     * Does not return anything
     * @IMPORTANT ApnsPHP_Push.disconnect() returned boolean (but never used result);
     * @IMPORTANT may break compatibility
     */
    public function disconnect(): void
    {
        if ($this->io) {
            $this->io->close();
            $this->io = null;
        }
    }

    /**
     * Sends all messages in the message queue to Apple Push Notification Service.
     *
     * @throws ApnsPHP_Push_Exception if not connected to the
     *         service or no notification queued.
     */
    public function send(): void
    {
        if (empty($this->_aMessageQueue)) {
            throw new ApnsPHP_Push_Exception('No notifications queued to be sent');
        }

        $this->_aErrors = [];
        $nRun = 1;

        while (($nMessages = count($this->_aMessageQueue)) > 0) {
            $this->_log("INFO: Processing messages queue, run #{$nRun}: $nMessages message(s) left in queue.");

            foreach ($this->_aMessageQueue as $k => &$aMessage) {
                if (!empty($aMessage['ERRORS'])) {
                    foreach ($aMessage['ERRORS'] as $aError) {
                        switch ($aError['statusCode']) {
                            case 0:
                                /**
                                 * No error
                                 */
                                $this->_log(
                                    "INFO: Message ID {$k} has no error ({$aError['statusCode']}), removing from queue..."
                                );
                                $this->_removeMessageFromQueue($k);

                                continue 2;

                                break;
                            default:
                                /**
                                 * Errors
                                 */
                                $this->_log(
                                    "WARNING: Message ID {$k} has error ({$aError['statusCode']}), removing from queue..."
                                );
                                $this->_removeMessageFromQueue($k, true);

                                continue 2;

                                break;
                        }
                    }
                } else {
                    /**
                     * Send Message -->
                     */
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                    $this->_log("STATUS: Sending #{$k}: " . strlen($aMessage['BINARY_NOTIFICATION']) . " bytes");

                    $readyWrite = $this->io->selectWrite(0, $this->_nSocketSelectTimeout);

                    if (false === $readyWrite) {
                        $this->_log('ERROR: Unable to wait for a write availability.');

                        throw new ApnsPHP_Push_Exception('Failed to select io stream for writing');
                    }

                    try {
                        $this->io->write($aMessage['BINARY_NOTIFICATION']);
                    } catch (Throwable $e) {
                        /**
                         * No reason to continue, failed to write explicitly
                         */
                        throw new ApnsPHP_Push_Exception($e->getMessage());
                    }
                    /**
                     * Send Message <--
                     */


                    /**
                     * Read Response -->
                     */
                    $nChangedStreams = $this->io->selectRead(0, $this->_nSocketSelectTimeout);

                    if (false === $nChangedStreams) {
                        $this->_log('ERROR: Unable to wait for a stream read availability.');

                        throw new ApnsPHP_Push_Exception('Failed to select io stream for reading');
                    }

                    if (0 === $nChangedStreams) {
                        /**
                         * After successful publish nothing is expected in response
                         * Timed-Out while waiting before anything interesting happened
                         */
                        $this->_aMessageQueue = [];
                    } elseif ($nChangedStreams > 0) {
                        /**
                         * Read the error message (or nothing) from stream and update the queue/cycle
                         */
                        if ($this->_updateQueue()) {
                            /**
                             * APNs returns an error-response packet and closes the connection
                             *
                             * _updateQueue modified the _aMessageQueue so it contains the error data,
                             * next cycle will deal with errors
                             */
                        } else {
                            /**
                             * If you send a notification that is accepted by APNs, nothing is returned.
                             */
                            $this->_aMessageQueue = [];
                        }
                    }
                    /**
                     * Read Response <--
                     */
                }
            }

            $nRun++;
        }
    }

    protected function _connect()
    {
        [$shost, $sport] = $this->_serviceURLs[$this->_nEnvironment];
        try {
            /**
             * @see http://php.net/manual/en/context.ssl.php
             */
            $ssl = ['verify_peer' => isset($this->_sRootCertificationAuthorityFile),
                'cafile'      => $this->_sRootCertificationAuthorityFile,
                'local_cert'  => $this->_sProviderCertificateFile,
                'disable_compression' => true];

            /**
             * Enabling SNI allows multiple certificates on the same IP address
             * @see http://php.net/manual/en/context.ssl.php
             * @see http://php.net/manual/en/openssl.constsni.php
             */
            if (defined('OPENSSL_TLSEXT_SERVER_NAME')) {
                $ssl['SNI_enabled'] = true;
            }

            $streamContext = stream_context_create(['ssl' => $ssl]);

            $this->io = new IO\StreamIO(
                $shost,
                $sport,
                $this->_nConnectTimeout,
                $this->_nReadWriteTimeout,
                $streamContext
            );
        } catch (Throwable $e) {
            throw new ApnsPHP_Exception("Unable to connect: " . $e->getMessage());
        }

        return true;
    }

    /**
     * APNs
     * 1. returns an error-response packet
     * 2. closes the connection
     *
     * Reads an error message (if present) from the main stream.
     * If the error message is present and valid the error message is returned,
     * otherwhise null is returned.
     *
     * @return @type array|null Return the error message array.
     */
    protected function _readErrorMessage()
    {
        try {
            $sErrorResponse = $this->io->read(self::ERROR_RESPONSE_SIZE);
        } catch (Throwable $e) {
            /**
             * Read IO exception exposed as Push exception so its catched properly
             */
            throw new ApnsPHP_Push_Exception($e->getMessage());
        }

        if (!$sErrorResponse) {
            /**
             * No response from APNS in (some period) time
             */
            return null;
        }

        if (self::ERROR_RESPONSE_SIZE !== strlen($sErrorResponse)) {
            throw new RuntimeException(
                'Unexpected response size: ' . strlen($sErrorResponse)
            );
        }

        $aErrorResponse = unpack('Ccommand/CstatusCode/Nidentifier', $sErrorResponse);

        if (empty($aErrorResponse)) {
            /**
             * In theory unpack ALWAYS returns array:
             * Returns an associative array containing unpacked elements of binary string.
             *
             * @see http://php.net/manual/en/function.unpack.php
             */
            throw new RuntimeException('Failed to unpack response data');
        }

        if (!isset($aErrorResponse['command'], $aErrorResponse['statusCode'], $aErrorResponse['identifier'])
            || self::ERROR_RESPONSE_COMMAND !== $aErrorResponse['command']) {
            throw new RuntimeException(
                'Unpacked error response has unexpected format: ' . json_encode($aErrorResponse)
            );
        }

        $aErrorResponse['time'] = time();

        $errMsg = 'Unknown error code: ' . $aErrorResponse['statusCode'];
        switch ($aErrorResponse['statusCode']) {
            case 0:
                $errMsg = 'No errors encountered';

                break;
            case 1:
                $errMsg = 'Processing error';

                break;
            case 2:
                $errMsg = 'Missing device token';

                break;
            case 3:
                $errMsg = 'Missing topic';

                break;
            case 4:
                $errMsg = 'Missing payload';

                break;
            case 5:
                $errMsg = 'Invalid token size';

                break;
            case 6:
                $errMsg = 'Invalid topic size';

                break;
            case 7:
                $errMsg = 'Invalid payload size';

                break;
            case 8:
                $errMsg = 'Invalid token';

                break;
            case 10:
                $errMsg = 'Shutdown';

                break;
            case 128:
                $errMsg = 'Protocol error';

                break;
            case 255:
                $errMsg = 'None (unknown)';

                break;
        }
        $aErrorResponse['statusMessage'] = $errMsg;

        return $aErrorResponse;
    }

    /**
     * Checks for error message and deletes messages successfully sent from message queue.
     *
     * @return bool whether error was detected.
     */
    protected function _updateQueue($aErrorMessage = null): bool
    {
        $error = $this->_readErrorMessage();

        if (empty($error)) {
            /**
             * If you send a notification that is accepted by APNs, nothing is returned.
             */
            return false;
        }

        $this->_log('ERROR: Unable to send message ID ' .
                    $error['identifier'] . ': ' .
                    $error['statusMessage'] . ' (' . $error['statusCode'] . ')');

        foreach ($this->_aMessageQueue as $k => &$aMessage) {
            if ($k < $error['identifier']) {
                /**
                 * Messages before X were successful
                 */
                unset($this->_aMessageQueue[$k]);
            } elseif ($k === $error['identifier']) {
                /**
                 * Append error to message error's list
                 */
                $aMessage['ERRORS'][] = $error;

                break;
            }

            throw new ApnsPHP_Push_Exception('Received error for unknown message identifier: ' . $error['identifier']);

            break;
        }

        return true;
    }
}
