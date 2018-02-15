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

namespace BackQ\Adapter\IO;

use \BackQ\Adapter\IO\Exception\RuntimeException;
use \BackQ\Adapter\IO\Exception\TimeoutException;

class StreamIO extends AbstractIO
{
    /**
     * Attempt to connect N times before give up
     * @var int
     */
    public $connAttempts        = 2;

    /**
     * Sleep between connection attempts
     * @var int
     */
    public $connRetryIntervalMs = 50;

    private $sock       = null;
    private $persistent = null;

    const FREAD_0_TRIES = 3;
    const WRITE_0_TRIES = 3;

    const READ_EOF_CODE  = 901;
    const READ_TIME_CODE = 902;
    const READ_ERR_CODE  = 900;

    /**
     * StreamIO constructor.
     *
     * @param      $host
     * @param      $port
     * @param      $connection_timeout
     * @param null $read_write_timeout
     * @param null $context
     * @param bool $blocking
     * @param string $persistent persistent connection identifier
     *
     * @throws \BackQ\Adapter\IO\Exception\RuntimeException
     * @throws \Exception
     */
    public function __construct($host, $port, $connection_timeout, $read_write_timeout = null, $context = null, $blocking = false, string $persistent = '')
    {
        $errstr = $errno  = null;
        $this->sock       = null;
        $this->persistent = ($persistent) ? true : false;
        $triesLeft        = $this->connAttempts;

        while (!$this->sock && $triesLeft > 0) {
            if ($context) {
                $remote = sprintf('tls://%s:%s/%s', $host, $port, strval($persistent));
                if ($persistent) {
                    $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $context);
                } else {
                    $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT, $context);
                }
            } else {
                $remote = sprintf('tcp://%s:%s/%s', $host, $port, strval($persistent));
                if ($persistent) {
                    $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT);
                } else {
                    $this->sock = @stream_socket_client($remote, $errno, $errstr, $connection_timeout, STREAM_CLIENT_CONNECT);
                }
            }
            if (!$this->sock) {
                $triesLeft--;
                usleep($this->connRetryIntervalMs * 1000);
            }
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

        if ($info['eof'] || @feof($this->sock))  {
            throw new TimeoutException('Error reading data. Socket connection EOF', self::READ_EOF_CODE);
        }

        if ($info['timed_out']) {
            throw new TimeoutException('Error reading data. Socket connection TIME OUT', self::READ_TIME_CODE);
        }

        /**
         * @todo add custom error handler as in write()
         */

        $tries = self::FREAD_0_TRIES;
        $fread_result = '';
        while (!@feof($this->sock) && strlen($fread_result) < $n) {
            /**
             * Up to $n number of bytes read.
             */
            $fdata = @fread($this->sock, $n);
            if (false === $fdata) {
                throw new RuntimeException("Failed to fread() from socket", self::READ_ERR_CODE);
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

    public function stream_set_timeout($read_write_timeout) {
        if (!stream_set_timeout($this->sock, $read_write_timeout)) {
            throw new \Exception("Timeout (stream_set_timeout) could not be set");
        }
    }

    public function write($data)
    {
        // get status of socket to determine whether or not it has timed out
        $info = @stream_get_meta_data($this->sock);

        if ($info['eof'] || @feof($this->sock)) {
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
        set_error_handler(function($severity, $text) {
        //$ohandler   = set_error_handler(function($severity, $text) {
            throw new \RuntimeException('fwrite() error (' . $severity . '): ' . $text);
        });

        $tries  = self::WRITE_0_TRIES;
        $len    = strlen($data);

        try {
            for ($written = 0; $written < $len; true) {

                $fwrite = fwrite($this->sock, substr($data, $written));
                fflush($this->sock);
                $written += intval($fwrite);

                if ($fwrite === false || (feof($this->sock) && $written < $len)) {
                    /**
                     * This bugged on 7.0.4 and maybe other versions
                     * @see https://bugs.php.net/bug.php?id=71907
                     * Actually returns int(0) instead of FALSE
                     *
                     * Some writes execute remote connection close, then its not uncommon to see
                     * connection being closed after write is successful
                     */
                    throw new RuntimeException("Failed to fwrite() to socket: " . ($len - $written) . 'bytes left');
                }

                if ($fwrite === 0) {
                    $tries--;
                }

                if ($tries <= 0) {
                    throw new RuntimeException('Failed to write to socket after ' . self::WRITE_0_TRIES . ' retries');
                }
            }

            /**
             * Restore original handlers after normal operations
             */
            error_reporting($oreporting);
            //set_error_handler($ohandler);
            restore_error_handler();
        } catch (\Exception $t) {
            /**
             * Restore original handlers if exception happens
             */
            error_reporting($oreporting);
            //set_error_handler($ohandler);
            restore_error_handler();
            throw new RuntimeException($t->getMessage(), $t->getCode());
        }
    }

    public function close()
    {
        if (is_resource($this->sock)) {
            stream_socket_shutdown($this->sock, STREAM_SHUT_RDWR);
            fclose($this->sock);
        }
        $this->sock = null;
    }

    /**
     * Returns a string of up to length bytes read, If an error occurs, returns false
     *
     * @param int    $length
     * @param string $delimiter
     *
     * @throws \BackQ\Adapter\IO\Exception\TimeoutException
     * @return string|false
     */
    public function stream_get_line(int $length, string $delimiter = "\r\n") {
        $info = stream_get_meta_data($this->sock);

        if ($info['eof'] || feof($this->sock))  {
            throw new TimeoutException('Error reading data. Socket connection EOF', self::READ_EOF_CODE);
        }

        if ($info['timed_out']) {
            throw new TimeoutException('Error reading data. Socket connection TIME OUT', self::READ_TIME_CODE);
        }
        /**
         * Reading ends when length bytes have been read,
         * when the string specified by ending is found (which is not included in the return value),
         * or on EOF (whichever comes first).
         */
        $data = stream_get_line($this->sock, $length, $delimiter);
        return $data;
    }

    /**
     * Reads remainder of a stream into a string, return a string or false on failure.
     *
     * @param int $length
     *
     * @throws \BackQ\Adapter\IO\Exception\TimeoutException
     * @return string|false
     */
    public function stream_get_contents(int $length) {
        $info = stream_get_meta_data($this->sock);

        if ($info['eof'] || feof($this->sock))  {
            throw new TimeoutException('Error reading data. Socket connection EOF', self::READ_EOF_CODE);
        }

        if ($info['timed_out']) {
            throw new TimeoutException('Error reading data. Socket connection TIME OUT', self::READ_TIME_CODE);
        }
        $data = stream_get_contents($this->sock, $length);
        return $data;
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

    public function isSocketReady() : bool {
        $info = stream_get_meta_data($this->sock);
        if ($info['eof'] || feof($this->sock) || $info['timed_out'])  {
            return true;
        }
        return false;
    }
}
