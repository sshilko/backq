<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2016 Sergey Shilko <contact@sshilko.com>
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

namespace BackQ\Adapter\IO;

use \BackQ\Adapter\IO\Exception\RuntimeException;
use \BackQ\Adapter\IO\Exception\TimeoutException;

class StreamIO extends AbstractIO
{
    private $sock = null;

    const FREAD_0_TRIES = 3;
    const WRITE_0_TRIES = 3;

    public function __construct($host, $port, $connection_timeout, $read_write_timeout = null, $context = null, $blocking = false)
    {
        $errstr = $errno = null;
        $this->sock = null;

        if ($context) {
            $remote = sprintf('tls://%s:%s', $host, $port);
            $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT, $context);
        } else {
            $remote = sprintf('tcp://%s:%s', $host, $port);
            $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT);
        }

        if (!$this->sock) {
            throw new RuntimeException("Error Connecting to server($errno): $errstr ");
        }

        if (null !== $read_write_timeout) {
            if (!stream_set_timeout($this->sock, $read_write_timeout)) {
                throw new \Exception("Timeout (stream_set_timeout) could not be set");
            }
        }

        /**
         * Manually set blocking & write buffer settings and make sure they are successfuly set
         * Use non-blocking as we dont want to stuck waiting for socket data while fread() w/o timeout
         */
        if (!stream_set_blocking($this->sock, $blocking)) {
            throw new \Exception ("Blocking could not be set");
        }

        $rbuff = stream_set_read_buffer($this->sock, 0);
        if (!(0 === $rbuff)) {
            throw new \Exception ("Read buffer could not be set");
        }

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
        stream_set_write_buffer($this->sock, 0);

        /**
         * Set small chunk size (default=4096/8192)
         * Setting this to small values (100bytes) still does NOT help detecting feof()
         */
        stream_set_chunk_size($this->sock, 1024);
    }

    public function read($n)
    {
        $info = stream_get_meta_data($this->sock);

        if ($info['eof'] || feof($this->sock))  {
            throw new TimeoutException("Error reading data. Socket connection EOF");
        }

        if ($info['timed_out']) {
            throw new TimeoutException("Error reading data. Socket connection TIMED OUT");
        }

        $tries = self::FREAD_0_TRIES;
        $fread_result = '';
        while (!feof($this->sock) && strlen($fread_result) < $n) {
            /**
             * Up to $n number of bytes read.
             */
            $fdata = fread($this->sock, $n);
            if (false === $fdata) {
                throw new RuntimeException("Failed to fread() from socket");
            }
            $fread_result .= $fdata;

            if (!$fdata) {
                $tries--;
            }

            if ($tries <= 0) {
                /**
                 * Nothing to read
                 */
                break;
            }

        }
        return $fread_result;
    }

    public function write($data)
    {
        // get status of socket to determine whether or not it has timed out
        $info = stream_get_meta_data($this->sock);

        if ($info['eof'] || feof($this->sock)) {
            throw new TimeoutException("Error sending data. Socket connection EOF");
        }

        if ($info['timed_out']) {
            throw new TimeoutException("Error sending data. Socket connection TIMED OUT");
        }

        /**
         * fwrite throws NOTICE error on broken pipe
         * send of N bytes failed with errno=32 Broken pipe or errno=2 SSL Broken pipe
         *
         * PHP Writes are buffered and stream_set_write_buffer() doesnt work correctly
         * with non-blocking streams, so even if fwrite() reports data is written
         * it doesnt mean system actualy sent the data and we caught feof()
         *
         * This happens when we didnt waited long enough for APNS to return error, and
         * continued sending the data, we will catch feof() only after some time
         */
        $oreporting = error_reporting(E_ALL);
        $ohandler   = set_error_handler(function($severity, $text) {
            throw new \RuntimeException('fwrite() error (' . $severity . '): ' . $text);
        });

        $tries  = self::WRITE_0_TRIES;
        $len    = strlen($data);

        for ($written = 0; $written < $len; $written += $fwrite) {

            $fwrite = fwrite($this->sock, substr($data, $written));
            if ($fwrite === false || feof($this->sock)) {
                /**
                 * This bugged on 7.0.4 and maybe other versions
                 * @see https://bugs.php.net/bug.php?id=71907
                 * Actually returns int(0) instead of FALSE
                 */
                throw new RuntimeException("Failed to fwrite() to socket");
            }

            if ($fwrite === 0) {
                $tries--;
            }

            if ($tries <= 0) {
                throw new RuntimeException('Failed to write to socket after ' . self::WRITE_0_TRIES . ' retries');
            }
        }

        /**
         * Restore original handlers
         */
        error_reporting($oreporting);
        set_error_handler($ohandler);
    }

    public function close()
    {
        if (is_resource($this->sock)) {
            stream_socket_shutdown($this->sock, STREAM_SHUT_RDWR);
            fclose($this->sock);
        }
        $this->sock = null;
    }

    public function selectWrite($sec, $usec) {
        $read   = null;
        $write  = array($this->sock);
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }

    public function selectRead($sec, $usec) {
        $read   = array($this->sock);
        $write  = null;
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }
}
