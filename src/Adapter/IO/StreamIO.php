<?php

namespace BackQ\Adapter\IO;

use \BackQ\Adapter\IO\Exception\RuntimeException;
use \BackQ\Adapter\IO\Exception\TimeoutException;

class StreamIO extends AbstractIO
{
    private $sock = null;

    public function __construct($host, $port, $connection_timeout, $read_write_timeout = null, $context = null, $blocking = 0)
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

    public function read($n, $unsafe = false)
    {
        if ($unsafe) {
            $info = stream_get_meta_data($this->sock);
            if (feof($this->sock) || $info['timed_out']) {
                return null;
            }
            return @fread($this->sock, $n);
        }
        $res = '';
        $read = 0;

        while ($read < $n && !feof($this->sock) &&
            (false !== ($buf = fread($this->sock, $n - $read)))) {

            $read += strlen($buf);
            $res .= $buf;
        }

        if (strlen($res)!=$n) {
            throw new RuntimeException("Error reading data. Received " .
                strlen($res) . " instead of expected $n bytes");
        }

        return $res;
    }

    public function write($data)
    {
        $len = strlen($data);
        while (true) {
            if (false === ($written = fwrite($this->sock, $data))) {
                throw new RuntimeException("Error sending data");
            }
            if ($written === 0) {
                throw new RuntimeException("Broken pipe or closed connection");
            }

            // get status of socket to determine whether or not it has timed out
            $info = stream_get_meta_data($this->sock);
            if ($info['timed_out']) {
                throw new TimeoutException("Error sending data. Socket connection timed out");
            }

            $len = $len - $written;
            if ($len > 0) {
                $data = substr($data,0-$len);
            } else {
                break;
            }
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

    public function select($sec, $usec)
    {
        $read   = array($this->sock);
        $write  = null;
        $except = null;
        return stream_select($read, $write, $except, $sec, $usec);
    }
}