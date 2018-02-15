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

    public function __destruct() {
        if (!empty($this->_config)) {
            if ($this->_config['persistent']) {
                return true;
            }
        }
        $this->disconnect();
    }

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

    /**
     * @param null $timeout not specifying timeout may result in undetected connection issue and infinite waiting time
     *
     * @return array|false
     */
    public function reserve($timeout = null) {
        /**
         * Writing will throw Exception on timeout -->
         */
        if (isset($timeout)) {
            $streamTimeout = $timeout + self::IO_TIMEOUT;
            $this->_io->stream_set_timeout($streamTimeout);
            $this->_write(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $streamTimeout = PHP_INT_MAX;
            /**
             * Dangerously long waiting time, also pretty optimistic to expect an answer w/o timeout,
             * NOT RECOMMENDED use reserve w/o timeout
             */
            $this->_io->stream_set_timeout($streamTimeout);
            $this->_write('reserve');
        }
        /**
         * Writing will throw Exception on timeout <--
         */

        /**
         * Read mig
         */
        $readio = $this->_read();
        $status = strtok($readio, ' ');

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
                break;
            /**
             * If a non-negative timeout was specified and the timeout exceeded before a job
             * became available, or if the client's connection is half-closed, the server
             * will respond with TIMED_OUT.
             */
            case 'TIMED_OUT':
                if (!isset($timeout)) {
                    $this->_error(__FUNCTION__ . " status = '" . $status . "', timeout=" . $streamTimeout);
                }
                /**
                 * Expected behaviour,
                 * we waited TIMEOUT period and no payload was received, basicly a HEARTBEAT
                 */
                return false;
                break;

            case 'DEADLINE_SOON':
            default:
                $this->_error(__FUNCTION__ . " status = '" . $status . "', timeout=" . $streamTimeout);
                return false;
        }
    }

    public function disconnect() {
        if ($this->connected) {
            try {
                $this->_write('quit');
                //$this->_io->close();
            } catch (\Exception $ex) {}
        }
        $this->_io = null;
        $this->connected = false;
        return $this->connected;
    }

    protected function _write($data) {
        if (!$this->connected) {
            $message = 'No connecting found while writing data to socket.';
            throw new RuntimeException($message);
        }
        $this->_io->write($data . "\r\n");
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
                if (false === $packet) {
                    /**
                     * stream_get_contents returns false on failure
                     */
                    throw new RuntimeException('Failed to io.stream_get_contents on ' . __FUNCTION__);
                }
                if ($packet) {
                    $packet = rtrim($packet, "\r\n");
                }
            } catch (IO\Exception\TimeoutException $ex) {
                if ($ex->getCode() == IO\StreamIO::READ_EOF_CODE) {
                    return false;
                } else {
                    throw new RuntimeException($ex->getMessage(), $ex->getCode());
                }
            }
        } else {
            /**
             * The number of bytes to read from the handle
             */
            $packet = $this->_io->stream_get_line(16384, "\r\n");
            if (false === $packet) {
                /**
                 * stream_get_line can also return false on failure
                 */
                throw new RuntimeException('Failed to io.stream_get_line on ' . __FUNCTION__);
            }
        }
        return $packet;
    }

    /**
     * Gives statistical information about the specified tube if it exists.
     *
     * @param string $tube Name of the tube.
     * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary.
     */
    public function statsTube($tube) {
        $cmd = sprintf('stats-tube %s', $tube);
        $this->_write($cmd);
        return $this->_statsRead($cmd);
    }

    protected function _statsRead($readWhat = '') {
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'OK':
                $data = $this->_read((integer) strtok(' '));
                return $this->_decode($data);
            default:
                $this->_error(__FUNCTION__ . ' after ' . $readWhat . ' got ' . $status . ' expected OK');
                return false;
        }
    }
}