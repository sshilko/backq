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
final class ApnsdPush extends \ApnsPHP_Push
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
    protected $_aServiceURLs = array(
        'tls://gateway.push.apple.com:2195',
        'tls://gateway.sandbox.push.apple.com:2195'
    );

    protected function _connect()
    {
        parent::_connect();

        /**
         * Manually set blocking & write buffer settings and make sure they are successfuly set
         * Use non-blocking as we dont want to stuck waiting for socket data while fread() w/o timeout
         */
        if (true === stream_set_blocking($this->_hSocket, 0) && 0 === stream_set_read_buffer($this->_hSocket, 0)) {

            /**
             * ! this is not reliably returns success (0)
             * ! but default buffer is pretty high (few Kb), so will not affect sending single small pushes
             *
             * Output using fwrite() is normally buffered at 8K.
             * This means that if there are two processes wanting to write to the same output stream (a file),
             * each is paused after 8K of data to allow the other to write.
             *
             * Ensures that all writes with fwrite() are completed
             * before other processes are allowed to write to that output stream
             */
            stream_set_write_buffer($this->_hSocket, 0);

            return true;
        } else {
            throw new \ApnsPHP_Exception("Unable to set connection parameters");
        }
    }
}
