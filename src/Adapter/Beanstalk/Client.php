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

namespace BackQ\Adapter\Beanstalk;

use BackQ\Adapter\IO;
use \BackQ\Adapter\IO\Exception\RuntimeException;

class Client extends \Beanstalk\Client {

    /**
     * @var IO\StreamIO
     */
    private $_io = null;

    const IO_TIMEOUT = 2;

    public function __construct(array $config = []) {
        $defaults = [
            'persistent' => true,
            'host' => '127.0.0.1',
            'port' => 11300,
            'timeout' => 1,
            'logger' => null
        ];
        $this->_config = array_merge($defaults, $config);
    }

    /**
     * Initiates a socket connection to the beanstalk server. The resulting
     * stream will not have any timeout set on it. Which means it can wait
     * an unlimited amount of time until a packet becomes available. This
     * is required for doing blocking reads.
     *
     * @see \Beanstalk\Client::$_connection
     * @see \Beanstalk\Client::reserve()
     * @return boolean `true` if the connection was established, `false` otherwise.
     */
    public function connect() {
        if (isset($this->_io)) {
            $this->disconnect();
        }

        $connectionTimeout = 1;
        if ($this->_config['timeout']) {
            $connectionTimeout = $this->_config['timeout'];
        }

        try {
            $this->_io = new IO\StreamIO($this->_config['host'],
                                        $this->_config['port'],
                                        $connectionTimeout,
                                        self::IO_TIMEOUT,
                                        null,
                                        true,
                                        $this->_config['persistent']);
            $this->connected = true;
        } catch (\Exception $ex) {
            $this->_error($ex->getCode() . ': ' . $ex->getMessage());
        }
        return $this->connected;
    }

    public function reserve($timeout = null) {
        if (isset($timeout)) {
            $this->_io->stream_set_timeout($timeout + self::IO_TIMEOUT);
            $this->_write(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $this->_io->stream_set_timeout(PHP_INT_MAX);
            $this->_write('reserve');
        }

        $status = strtok($this->_read(), ' ');

        /**
         * Read is blocking
         *
         * we write and then we try to read from the stream until: TIMEOUT is reached OR payload received
         * then restore general timeout
         */
        $this->_io->stream_set_timeout(self::IO_TIMEOUT);

        switch ($status) {
            case 'RESERVED':
                return [
                    'id' => (integer) strtok(' '),
                    'body' => $this->_read((integer) strtok(' '))
                ];
            case 'DEADLINE_SOON':
            case 'TIMED_OUT':
            default:
                $this->_error($status);
                return false;
        }
    }

    public function disconnect() {
        if ($this->connected) {
            try {
                $this->_write('quit');
            } catch (\Exception $ex) {}
        }
        $this->connected = false;
        return $this->connected;
    }

    protected function _write($data) {
        if (!$this->connected) {
            $message = 'No connecting found while writing data to socket.';
            throw new RuntimeException($message);
        }
        try {
            $this->_io->write($data . "\r\n");
        } catch (\Exception $e) {
            throw new $e;
        }
        return strlen($data);
    }

    protected function _read($length = null) {
        if (!$this->connected) {
            $message = 'No connection found while reading data from socket.';
            throw new RuntimeException($message);
        }

        if ($length) {
            try {
                $packet = $this->_io->stream_get_contents($length + 2);
                $packet = rtrim($packet, "\r\n");
            } catch (IO\Exception\TimeoutException $ex) {
                if ($ex->getCode() == IO\StreamIO::READ_EOF_CODE) {
                    return false;
                } else {
                    throw new RuntimeException($ex->getMessage(), $ex->getCode());
                }
            }
        } else {
            $packet = $this->_io->stream_get_line(16384, "\r\n");
        }
        return $packet;
    }
}