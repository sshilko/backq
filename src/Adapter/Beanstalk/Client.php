<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\Beanstalk;

use BackQ\Adapter\IO;
use BackQ\Adapter\IO\Exception\RuntimeException;
use Override;
use Throwable;
use function array_merge;
use function array_slice;
use function explode;
use function intval;
use function is_numeric;
use function is_string;
use function ltrim;
use function rtrim;
use function sprintf;
use function strlen;
use function strpos;
use function strtok;
use const PHP_INT_MAX;

/**
 * @phpcs:disable
 */
class Client extends \Beanstalk\Client
{

    protected const IO_TIMEOUT = 2;

    private ?IO\StreamIO $_io = null;

    public function __construct(array $config = [])
    {
        $defaults = [
            'host' => '127.0.0.1',
            'logger' => null,
            'persistent' => true,
            'port' => 11300,
            'timeout' => 1,
        ];
        $this->_config = array_merge($defaults, $config);
    }

    #[Override]
    public function __destruct()
    {
        /**
         * @var array{persistent?: bool} $config
         */
        $config = $this->_config;
        if (empty($config['persistent'])) {
            $this->disconnect();
        }
    }

    /**
     * Initiates a socket connection to the beanstalk server. The resulting
     * stream will not have any timeout set on it. Which means it can wait
     * an unlimited amount of time until a packet becomes available. This
     * is required for doing blocking reads.
     *
     * @see \Beanstalk\Client::$_connection
     * @see \Beanstalk\Client::reserve()
     * @return bool `true` if the connection was established, `false` otherwise.
     */
    #[Override]
    public function connect(): bool
    {
        if (isset($this->_io)) {
            $this->disconnect();
        }

        /**
         * @var array{host: string, port: int, timeout: int, persistent: bool} $config
         */
        $config = $this->_config;

        $connectionTimeout = 1;
        if ($config['timeout']) {
            $connectionTimeout = $config['timeout'];
        }

        try {
            $this->_io = new IO\StreamIO(
                (string) $config['host'],
                (int) $config['port'],
                (float) $connectionTimeout,
                self::IO_TIMEOUT,
                null,
                true,
                (bool) $config['persistent']
            );
            $this->connected = true;
        } catch (Throwable $ex) {
            $this->_error($ex->getCode() . ': ' . $ex->getMessage());
        }

        return $this->connected;
    }

    /**
     * @param int|null $timeout not specifying timeout may result in undetected connection issue and infinite waiting time
     *
     * @throws RuntimeException
     *
     * @return array|false
     */
    #[Override]
    public function reserve($timeout = null)
    {
        $io = $this->_io;
        if (null === $io) {
            throw new RuntimeException('No active connection, call connect() first');
        }

        /**
         * Writing will throw Exception on timeout -->
         */
        if (isset($timeout)) {
            $streamTimeout = $timeout + self::IO_TIMEOUT;
            $io->stream_set_timeout($streamTimeout);
            $this->_write(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $streamTimeout = PHP_INT_MAX;
            /**
             * Dangerously long waiting time, also pretty optimistic to expect an answer w/o timeout,
             * NOT RECOMMENDED use reserve w/o timeout
             */
            $io->stream_set_timeout($streamTimeout);
            $this->_write('reserve');
        }
        /**
         * Writing will throw Exception on timeout <--
         */

        /**
         * Read mig
         */
        $readio = $this->_read();
        $status = is_string($readio) ? (string) strtok($readio, ' ') : '';

        /**
         * Every subsequent call to strtok only needs the token to use,
         * as it keeps track of where it is in the current string
         * @see http://php.net/manual/en/function.strtok.php
         */

        /**
         * is the job id -- an integer unique to this job in this instance of
         * beanstalkd.
         */
        $jobid  = intval(strtok(' '));

        /**
         * is an integer indicating the size of the job body, not including
         * the trailing "\r\n"
         */
        $bodyN  = intval(strtok(' '));

        /**
         * Read is blocking
         *
         * we write and then we try to read from the stream until: TIMEOUT is reached OR payload received
         * then restore general timeout
         */
        $io->stream_set_timeout(self::IO_TIMEOUT);

        switch ($status) {
            case 'RESERVED':
                return [
                    'id'   => $jobid,
                    'body' => $this->_read($bodyN),
                ];
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
            case 'DEADLINE_SOON':
                $this->_error(__FUNCTION__ . " status = '" . $status . "', timeout=" . $streamTimeout);

                return false;
            default:
                throw new RuntimeException(
                    __FUNCTION__ . " connection failure, status = '" . $status . "', timeout=" . $streamTimeout
                );
        }
    }

    #[Override]
    public function disconnect()
    {
        if ($this->connected) {
            try {
                $this->_write('quit');
                //$this->_io->close();
            } catch (Throwable $ex) {
                $this->_error(__FUNCTION__ . " error: " . $ex->getMessage());
            }
        }
        $this->_io = null;
        $this->connected = false;

        return $this->connected;
    }

    #[Override]
    protected function _write($data)
    {
        if (!$this->connected) {
            $message = 'No connecting found while writing data to socket.';

            throw new RuntimeException($message);
        }

        $io = $this->_io;
        if (null === $io) {
            throw new RuntimeException('No active connection, call connect() first');
        }
        $io->write($data . "\r\n");

        return strlen($data);
    }

    /**
     * @throws RuntimeException
     *
     * @return string|false
     *
     * @phpcs:disable SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
     * @suppress PhanUndeclaredTypeReturnType, PhanParamSignatureRealMismatchHasParamType, PhanParamSignatureMismatch 
     */
    #[Override]
    protected function _read(int|null $length = null)
    {
        if (!$this->connected) {
            $message = 'No connection found while reading data from socket.';

            throw new RuntimeException($message);
        }

        $io = $this->_io;
        if (null === $io) {
            throw new RuntimeException('No active connection, call connect() first');
        }

        if ($length) {
            try {
                /**
                 * +2 for trailing "\r\n"
                 */
                $packet = $io->stream_get_contents($length + 2);
                if (false === $packet) {
                    /**
                     * stream_get_contents returns false on failure
                     */
                    throw new RuntimeException('Failed to io.stream_get_contents on ' . __FUNCTION__);
                }
                if ('' !== $packet) {
                    $packet = rtrim($packet, "\r\n");
                }
            } catch (IO\Exception\TimeoutException $ex) {
                if (IO\StreamIO::READ_EOF_CODE === $ex->getCode()) {
                    return false;
                }

                /**
                 * @psalm-suppress RedundantCast
                 */
                throw new RuntimeException($ex->getMessage(), (int) $ex->getCode());
            }
        } else {
            /**
             * The number of bytes to read from the handle
             */
            $packet = $io->stream_get_line(32768, "\r\n");
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
     * @param bool|string $decode
     */
    #[Override]
    protected function _statsRead($decode = true)
    {
        $status = (string) strtok((string) $this->_read(), ' ');

        switch ($status) {
            case 'OK':
                $data = $this->_read((int) strtok(' '));
                if (!is_string($data)) {
                    $this->_error(__FUNCTION__ . ' failed to read stats body');

                    return false;
                }

                return $this->_decode($data);
            default:
                $this->_error(__FUNCTION__ . ' after ' . (string) $decode . ' got ' . $status . ' expected OK');

                return false;
        }
    }

    /**
     * Decodes YAML data. This is a super naive decoder which just works on
     * a subset of YAML which is commonly returned by beanstalk.
     *
     * The vendored decoder reads $value[0] on every line, which raises
     * "Uninitialized string offset 0" on PHP 8 when the YAML ends with a
     * trailing newline (the final explode() element is empty). Skip empty
     * lines instead.
     *
     * @param string $data The data in YAML format, can be either a list or a dictionary.
     * @return array An (associative) array of the converted data.
     */
    #[Override]
    protected function _decode($data)
    {
        $data = array_slice(explode("\n", $data), 1);
        $result = [];

        foreach ($data as $key => $value) {
            if ('' === $value) {
                continue;
            }
            if ($value[0] === '-') {
                $value = ltrim($value, '- ');
            } elseif (strpos($value, ':') !== false) {
                $parts = explode(':', $value, 2);
                $key = $parts[0];
                $value = ltrim($parts[1] ?? '', ' ');
            }
            if (is_numeric($value)) {
                $value = (integer) $value == $value ? (integer) $value : (float) $value;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
