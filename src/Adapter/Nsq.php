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

use DateTime;
use Override;
use RuntimeException;
use Throwable;
use function array_merge;
use function floor;
use function gethostname;
use function getmypid;
use function is_array;
use function json_decode;
use function json_encode;
use function pack;
use function round;
use function sprintf;
use function strlen;
use function substr;
use function trim;
use function unpack;

/**
 * Class Nsq
 * @package BackQ\Adapter
 */
class Nsq extends AbstractAdapter
{
    /**
     * Request messages
     */
    protected final const string PROTO_VERSION   = "  V2";
    protected final const string PROTO_IDENTIFY  = "IDENTIFY";
    protected final const string PROTO_PUBLISH   = "PUB %s";
    protected final const string PROTO_PUBDELAY  = "DPUB %s %s";
    protected final const string PROTO_SUBSCRIBE = "SUB %s %s";
    protected final const string PROTO_READY     = "RDY %s";
    protected final const string PROTO_REQUEUE   = "REQ %s %s";
    protected final const string PROTO_FINISH    = "FIN %s";
    protected final const string PROTO_NOOP      = "NOP";
    protected final const string PROTO_AUTH      = "AUTH";
    protected final const string PROTO_CLOSE     = "CLS";
    protected final const string RESPONSE_HEARTBEAT = "_heartbeat_";
    protected final const string RESPONSE_SUCCESS   = "OK";
    protected final const string RESPONSE_CLOSED    = "CLOSE_WAIT";
    protected final const int FRAME_TYPE_RESPONSE = 0;
    protected final const int FRAME_TYPE_ERROR    = 1;
    protected final const int FRAME_TYPE_MESSAGE  = 2;
    protected final const float HEARTBEAT_TTR_RATION = 1.5;
    protected final const string IDENTIFY_USER_AGENT = "BackQ\Nsq";
    protected final const int STATE_BINDWRITE = 1;
    protected final const int STATE_BINDREAD  = 2;
    protected final const int STATE_NOTHING   = 0;

    protected string $host;

    protected int $port;

    protected $config = [
        "auth" => "",
        "connection_timeout" => 2,
        "stream_set_timeout" => 10,
        'host' => null,
        'port' => null,
        /**
         * A separate TCP connection should be made to each nsqd
         * for each topic the consumer wants to subscribe to.
         * @see http://nsq.io/clients/building_client_libraries.html
         */
        'persistent' => false,
        /**
         * DEFAULT Milliseconds between heartbeats
         * Cannot SUB with heartbeats disabled (-1)
         * This essentially sets the pickTask(TIMEOUT) value
         *
         * Any changes to heartbeat_interval_ms MUST be done set via setWorkTimeout(N) BEFORE connecting
         */
        'heartbeat_interval_ms' => 5000,
        /**
         * If the message handler requires more time than the configured message timeout,
         * the TOUCH command can be used to reset the timer on the nsqd side.
         * This can be done repeatedly until the message is either FIN or REQ,
         * up to the sending nsqd’s configured `--max-req-timeout=48h0m1s`.
         *
         * Client libraries should never automatically TOUCH on behalf of the consumer.
         * server-side message timeout in seconds for messages delivered to this client
         *
         * The sending nsqd expects to receive a reply within its configured message timeout
         */
        'msg_timeout' => self::JOBTTR_DEFAULT,
        /**
         * Your server configured --max-req-timeout value, used for validation only,
         * you can optionally provide the value to prevent attempt of request that will fail,
         * can provide any big value (i.e. PHP_INT_MAX) or null to disable validation
         */
        'max_req_timeout' => null];

    protected $authentication = false;

    private ?IO\StreamIO $_io = null;

    private $connected = false;

    private ConnectionState $state = ConnectionState::Nothing;

    private $stateData = [];

    public function __construct(string $host = "127.0.0.1", int $port = 4150, array $config = [])
    {
        $this->config['host']     = $host;
        $this->config['port']     = $port;
        $this->config['clientId'] = (string) gethostname() . '_' . (string) getmypid();

        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        if ($seconds >= 1) {
            /**
             * 1000ms is minimum hearbeat
             */
            $this->config['heartbeat_interval_ms'] = $seconds * 1000;
        }
    }

    /**
     * Disconnects from queue
     */
    #[Override]
    public function disconnect(): bool
    {
        if (true === $this->connected) {
            try {
                if (ConnectionState::BindRead === $this->state) {
                    /**
                     * When subscribed to receive new messages, nice way of closing the connection
                     * is "RDY 0" (pause messages) followed by "CLS" (cleanly close connection)
                     */
                    $this->writeCommand(self::PROTO_CLOSE);
                    $this->readSuccessResponse(self::RESPONSE_CLOSED);
                }
                $io = $this->_io;
                \assert($io instanceof IO\StreamIO);
                $io->close();
            } catch (Throwable $ex) {
                $this->logError(self::class . ' ' . __FUNCTION__ . ': ' . $ex->getMessage());
            }

            $this->state     = ConnectionState::Nothing;
            $this->stateData = [];
            $this->connected = false;
            $this->_io       = null;

            return true;
        }

        return false;
    }

    /**
     * Returns TRUE if connection is alive
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        if ($this->connected && $this->_io) {
            return $this->_io->isSocketReady();
        }

        return false;
    }

    /**
     * After failed work processing
     *
     */
    #[Override]
    public function afterWorkFailed(int|string|null $workId): bool
    {
        if ($this->connected && ConnectionState::BindRead === $this->state) {
            $this->writeCommand(sprintf(self::PROTO_REQUEUE, (string) $workId, 0));

            return true;
        }

        return false;
    }

    /**
     * After successful work processing
     *
     */
    #[Override]
    public function afterWorkSuccess(int|string|null $workId): bool
    {
        if ($workId && $this->connected && ConnectionState::BindRead === $this->state) {
            $this->writeCommand(sprintf(self::PROTO_FINISH, $workId));

            return true;
        }

        return (bool) $workId;
    }

    /**
     * Subscribe for new incoming data
     *
     */
    #[Override]
    public function bindRead(string $queue): bool
    {
        if ($this->connected && ConnectionState::Nothing === $this->state) {
            /**
             * A channel can, and generally does, have multiple clients connected.
             * Assuming all connected clients are in a state where they are ready to receive messages,
             * each message will be delivered to a random client
             */
            $this->writeCommand(sprintf(self::PROTO_SUBSCRIBE, $queue, $queue));
            $this->readSuccessResponse();

            $this->state = ConnectionState::BindRead;
            $this->stateData = ['queue' => $queue,
                'rdy'   => 0];

            return true;
        }

        return false;
    }

    /**
     * Prepare to write data into queue
     *
     */
    #[Override]
    public function bindWrite(string $queue): bool
    {
        if ($this->connected && ConnectionState::Nothing === $this->state) {
            $this->state = ConnectionState::BindWrite;
            $this->stateData = ['queue' => $queue];

            return true;
        }

        return false;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * NSQ has no concept of "worker availability" for a topic when the client
     * is connected directly to nsqd, so this always reports "no workers".
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        $this->logInfo(self::class . '.' . __FUNCTION__ . ' not supported, reporting no workers');

        return false;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return bool|array [id, payload]
     */
    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        if ($timeout) {
            $this->logInfo(self::class . '.' . __FUNCTION__ . ' arguments deprecated');
            //$timeout = null;
        }

        if ($this->connected && ConnectionState::BindRead === $this->state) {
            if ($this->stateData['rdy'] < 1) {
                $this->writeReady(1);
                $this->stateData['rdy'] = 1;
            }

            /**
             * This should return task or return notning after timeout
             */
            [$frameType, $messageFrame] = $this->readFrame(true);

            if (self::RESPONSE_HEARTBEAT === $messageFrame) {
                /**
                 * Manually handle heartbeats
                 * Heartbeats are breaks/timeouts in the pickTask cycle
                 */
                $this->writeCommand(self::PROTO_NOOP);

                return false;
            }

            if (self::FRAME_TYPE_MESSAGE !== $frameType) {
                throw new RuntimeException(sprintf(
                    "was expecting a message frame, got '%s' with data '%s'",
                    $frameType,
                    $messageFrame
                ));
            }

            $this->stateData['rdy'] -= 1;

            $message = substr($messageFrame, 26);
            $msgId   = substr($messageFrame, 10, 16);

            $time = floor($this->unpackField('J', substr($messageFrame, 0, 8)) / 1000000000);

            $readyAt = DateTime::createFromFormat('U', (string) $time);
            if (false === $readyAt) {
                throw new RuntimeException('Failed to parse message timestamp as a DateTime');
            }

            return [$msgId, $message, [
                'attempts' => $this->unpackField('n', substr($messageFrame, 8, 2)),
                'time'     => $readyAt->format('c'),
            ]];
        }

        return false;
    }

    /**
     * Put task into queue
     *
     * @param  string $data The job body.
     */
    #[Override]
    public function putTask(string $body, array $params = []): bool
    {
        /**
         * @todo add support fot $params args
         */
        if ($this->connected && ConnectionState::BindWrite === $this->state) {
            if (isset($params[self::PARAM_JOBTTR])) {
                if ($params[self::PARAM_JOBTTR] > $this->config['msg_timeout']) {
                    /**
                     * Too big TTR value for this worker
                     * The in-flight timeout expires and nsqd automatically re-queues the message.
                     * @see http://nsq.io/clients/building_client_libraries.html
                     */
                    throw new RuntimeException('Desired ' . self::PARAM_JOBTTR .
                                               ' param ' . $params[self::PARAM_JOBTTR] .
                                               '> ' . $this->config['msg_timeout'] . ' msg_timeout, ' .
                                               'NSQ expects answer within ' . $this->config['msg_timeout'] . ' seconds');
                }

                /**
                 * After 2 unanswered _heartbeat_ responses,
                 * nsqd will timeout and forcefully close a client connection that it has not heard from
                 * @see http://nsq.io/clients/building_client_libraries.html
                 */
                if ($this->config['heartbeat_interval_ms'] &&
                    (round($params[self::PARAM_JOBTTR] * 1000) > round(
                        $this->config['heartbeat_interval_ms'] * self::HEARTBEAT_TTR_RATION
                    ))
                ) {
                    throw new RuntimeException('Desired ' . self::PARAM_JOBTTR .
                                               ' param ' . $params[self::PARAM_JOBTTR] .
                                               's > ' . (string) ($this->config['heartbeat_interval_ms'] * self::HEARTBEAT_TTR_RATION) . 'ms (x' . (string) self::HEARTBEAT_TTR_RATION . ' heartbeat), ' .
                                               'NSQ expects answer within two hearbeats, but cannot guarantee that, ' .
                                               'please configure your heartbeat_interval_ms to extend heartbeat intervals');
                }
            }

            if (isset($params[self::PARAM_READYWAIT])) {
                if ($this->config['max_req_timeout']
                    && ($params[self::PARAM_READYWAIT] > $this->config['max_req_timeout'])
                ) {
                    /**
                     * Too big delay value for this server, value is configured on server side
                     * Preemptively decline sending jobs that server will reject
                     */
                    throw new RuntimeException('Desired ' . self::PARAM_READYWAIT . ' of ' .
                                               $params[self::PARAM_READYWAIT] . ' seconds is longer ' .
                                               'than specified server configured --max_req_timeout ' .
                                               'of ' . $this->config['max_req_timeout']);
                }
                /**
                 * Delay ready state by N seconds
                 * maximum value is limited to `nsqd --max-req-timeout` value
                 */
                $this->writeCommandWithBody(
                    sprintf(
                        self::PROTO_PUBDELAY,
                        $this->stateData['queue'],
                        $params[self::PARAM_READYWAIT] * 1000
                    ),
                    $body
                );
            } else {
                $this->writeCommandWithBody(
                    sprintf(self::PROTO_PUBLISH, $this->stateData['queue']),
                    $body
                );
            }
            $this->readSuccessResponse();

            return true;
        }

        return false;
    }

    /**
     * connect and negotiate protocol
     */
    #[Override]
    public function connect(): bool
    {
        if (isset($this->_io) || $this->connected) {
            $this->disconnect();
        }

        try {
            $this->_io = new IO\StreamIO(
                (string) $this->config['host'],
                (int) $this->config['port'],
                (float) $this->config['connection_timeout'],
                (int) $this->config['stream_set_timeout'],
                null,
                true,
                (bool) $this->config['persistent']
            );
            $this->connected = true;

            $this->write(self::PROTO_VERSION);
            $this->writeIdentify();
        } catch (Throwable $ex) {
            $this->logError($ex->getCode() . ': ' . $ex->getMessage());
        }

        return $this->connected;
    }

    /**
     * write the identify command to the connected socket, with out negotiate options
     */
    private function writeIdentify(): void
    {
        if (ConnectionState::Nothing !== $this->state) {
            throw new RuntimeException('Incorrect protocol usage while ' . __FUNCTION__);
        }

        $identify = [
            "client_id"           => $this->config['clientId'],
            "feature_negotiation" => true,
            "hostname"            => gethostname(),
            "user_agent"          => self::IDENTIFY_USER_AGENT,
            'msg_timeout'         => $this->config['msg_timeout'] * 1000,
        ];

        $identify["heartbeat_interval"] = $this->config['heartbeat_interval_ms'];

        $this->writeCommandWithBody(
            self::PROTO_IDENTIFY,
            json_encode($identify, JSON_THROW_ON_ERROR)
        );

        [$frameType, $response] = $this->readFrame();
        if (self::FRAME_TYPE_RESPONSE !== $frameType) {
            throw new RuntimeException(
                sprintf("was expecting feature list response, got '%s' with data '%s'", $frameType, $response)
            );
        }

        $features = [];
        if (is_string($response) && json_validate($response)) {
            $features = json_decode($response, true);
        }

        if (!is_array($features)) {
            throw new RuntimeException(
                sprintf("was expecting feature list response, got '%s' with data '%s'", $frameType, $response)
            );
        }

        if (!empty($features['auth_required'])) {
            if (empty($this->config['auth'])) {
                throw new RuntimeException("Authentication is required, but not provided in the config");
            }

            $this->writeCommandWithBody(self::PROTO_AUTH, (string) $this->config['auth']);
            $this->authentication = $this->readAuthenticationHeader();
        }
    }

    /**
     * write the ready command to the connected socket - this sends the size of batch of messages the client can handle
     *
     * @param int number of messages to batch up and send to this client at once
     *
     * @psalm-param 1 $numberOfMessages
     */
    private function writeReady(int $numberOfMessages): bool
    {
        if ($this->connected && ConnectionState::BindRead === $this->state) {
            $this->writeCommand(sprintf(self::PROTO_READY, $numberOfMessages));

            return true;
        }

        return false;
    }

    /**
     * calling this blocks and waits for a single frame from the socket, and checks for expected OK response
     * throws an exception if anything else is received instead
     *
     * @throws RuntimeException
     *
     * @param string|null $expectedResponse
     *
     * @psalm-param 'CLOSE_WAIT'|null $expectedResponse
     */
    private function readSuccessResponse(string|null $expectedResponse = null): void
    {
        [$frameType, $response] = $this->readFrame();
        if ($expectedResponse && self::FRAME_TYPE_RESPONSE === $frameType) {
            if ($expectedResponse === $response) {
                return;
            }
        }

        if (self::FRAME_TYPE_RESPONSE !== $frameType || self::RESPONSE_SUCCESS !== $response) {
            throw new RuntimeException(
                sprintf(__FUNCTION__ . " expecting Success, got '%s' with data '%s'", $frameType, $response)
            );
        }
    }

    private function readAuthenticationHeader(): array
    {
        [$frameType, $response] = $this->readFrame();

        if (self::FRAME_TYPE_RESPONSE !== $frameType) {
            throw new RuntimeException('Authentication Failure:' . $response);
        }

        $authentication = @json_decode($response, true);
        if (!is_array($authentication)) {
            throw new RuntimeException(
                sprintf("was expecting authentication response, got '%s' with data '%s'", $frameType, $response)
            );
        }

        return $authentication;
    }

    /**
     * generic helper function to write a command that had a body
     *
     * @param string $command the command name
     * @param string $body the command body to be sent with the command
     */
    private function writeCommandWithBody($command, $body): void
    {
        $length = pack("N", strlen($body));

        $this->writeCommand($command);
        $this->write($length . $body);
    }

    /**
     * write command w/o body
     * @param string $command
     */
    private function writeCommand(string $cmd): void
    {
        $this->write($cmd . "\n");
    }

    /**
     * generic command to write a buffer to the socket and make sure nothing bad happens in the process
     *
     * @param string $buffer the full buffer to write to the socket
     */
    private function write($buffer): void
    {
        $this->logInfo('--> writing ' . trim($buffer));
        $io = $this->_io;
        \assert($io instanceof IO\StreamIO);
        $io->write($buffer);
    }

    /**
     * @param bool $raw return raw frame whatever kind of frame it is
     *
     */
    private function readFrame($raw = false): array
    {
        /**
         * @see Data Format http://nsq.io/clients/tcp_protocol_spec.html
         */
        $frameType = null;
        $frameData = null;

        while (true) {
            $frameSize = $this->readInt();
            $frameType = $this->readInt();

            $frameData = $this->read($frameSize - 4);

            $this->logInfo("received frameType=" . $frameType);
            if ($raw) {
                break;
            }

            if (self::RESPONSE_HEARTBEAT !== $frameData) {
                break;
            }

            $this->writeCommand(self::PROTO_NOOP);
        }

        return [$frameType, $frameData];
    }

    /**
     * read and unpack a 32bit binary INT
     */
    private function readInt(): int
    {
        $bytes = $this->read(4);

        return $this->unpackField('N', $bytes);
    }

    /**
     * read from the socket a set size of data
     *
     * @param int $size
     */
    private function read(int $size): string
    {
        $this->logInfo('--> reading ' . $size . ' bytes');
        $io = $this->_io;
        \assert($io instanceof IO\StreamIO);
        $result = $io->read($size);
        if ($size !== strlen($result)) {
            throw new RuntimeException('Failed to read ' . $size . ' bytes from IO');
        }
        $this->logInfo('<-- reading ' . $size . ' bytes');

        return $result;
    }

    /**
     * unpack a binary message field, throws when the data is not decodeable
     */
    private function unpackField(string $format, string $data): int
    {
        $unpacked = unpack($format, $data);
        if (false === $unpacked || !isset($unpacked[1])) {
            throw new RuntimeException('Failed to unpack frame data');
        }

        return (int) $unpacked[1];
    }
}
