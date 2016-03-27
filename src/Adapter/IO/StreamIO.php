<?php

namespace BackQ\Adapter\IO;

use \BackQ\Adapter\IO\Exception\RuntimeException;
use \BackQ\Adapter\IO\Exception\TimeoutException;

class StreamIO extends AbstractIO
{
    private $sock = null;

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
    }

    public function read($n)
    {
        $info = stream_get_meta_data($this->sock);
        if ($info['eof'] || $info['timed_out'] || feof($this->sock)) {
            throw new TimeoutException("Error reading data. Socket connection timed out");
        }

        $fread_result = '';
        while (!feof($this->sock) && strlen($fread_result) < $n) {
            /**
             * Up to $n number of bytes read.
             */
            $fdata = fread($this->sock, $n);
            if (false === $fdata) {
                throw new RuntimeException("Failed to read from stream IO");
            }
            $fread_result .= $fdata;
        }
        return $fread_result;
    }

    public function write($data)
    {
        // get status of socket to determine whether or not it has timed out
        $info = stream_get_meta_data($this->sock);
        if ($info['eof'] || $info['timed_out'] || feof($this->sock)) {
            throw new TimeoutException("Error sending data. Socket connection timed out");
        }

        $fwrite = 0;
        $len    = strlen($data);

        /**
         * fwrite throws NOTICE error on broken pipe
         * send of N bytes failed with errno=32 Broken pipe
         */
        $oreporting = error_reporting(E_ALL);
        $ohandler   = set_error_handler(function($severity, $text) {
            throw new \RuntimeException('Error (' . $severity . '): ' . $text);
        });

        $tries = 3;
        for ($written = 0; $written < $len; $written += $fwrite) {

            $fwrite = fwrite($this->sock, substr($data, $written));
            if ($fwrite === false) {
                /**
                 * This bugged on 7.0.4 and maybe other versions
                 * @see https://bugs.php.net/bug.php?id=71907
                 * Actually returns int(0) instead of FALSE
                 */
                throw new RuntimeException("Error sending data");
            }

            if ($fwrite === 0) {
                $tries--;
            }

            if ($tries <= 0) {
                throw new RuntimeException('Failed to write to socket after ' . $tries . ' retries');
            }
        }

        /**
         * Restore original handlers
         */
        error_reporting($oreporting);
        set_error_handler($ohandler);

        if ($fwrite === 0) {
            throw new RuntimeException("Broken pipe or closed connection");
        }

    }

    public function close()
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = null;
    }

    public function get_socket()
    {
        return $this->sock;
    }

    public function selectWrite($sec, $usec) {
        $read   = null;
        $write  = array($this->sock);
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }

    public function selectRead($sec, $usec) {
        return $this->select($sec, $usec);
    }

    /**
     * Check if stream is available for READING,
     * timed-out streams are also successfuly returned
     *
     * @param $sec
     * @param $usec
     *
     * @return int
     */
    public function select($sec, $usec)
    {
        $read   = array($this->sock);
        $write  = null;
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }
}
