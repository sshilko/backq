<?php
/**
 *  The MIT License (MIT)
 *
 * Copyright (c) 2018 Sergei Shilko <contact@sshilko.com>
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
namespace BackQ\Adapter;

use Datetime;
use RuntimeException;

/**
 * Class Nsq
 * @package BackQ\Adapter
 */
class Nsq extends AbstractAdapter
{
    /**
     * Request messages
     */
    const PROTO_VERSION   = "  V2";
    const PROTO_IDENTIFY  = "IDENTIFY";
    const PROTO_PUBLISH   = "PUB %s";
    const PROTO_PUBDELAY  = "DPUB %s %s";
    const PROTO_SUBSCRIBE = "SUB %s %s";
    const PROTO_READY     = "RDY %s";
    const PROTO_REQUEUE   = "REQ %s %s";
    const PROTO_FINISH    = "FIN %s";
    const PROTO_NOOP      = "NOP";
    const PROTO_AUTH      = "AUTH";
    const PROTO_CLOSE     = "CLS";

    /**
     * Responses expected from the server
     */
    const RESPONSE_HEARTBEAT = "_heartbeat_";
    const RESPONSE_SUCCESS   = "OK";
    const RESPONSE_CLOSED    = "CLOSE_WAIT";

    /**
     * Reponse frame types defined in the protocol
     */
    const FRAME_TYPE_RESPONSE = 0;
    const FRAME_TYPE_ERROR    = 1;
    const FRAME_TYPE_MESSAGE  = 2;

    const HEARTBEAT_TTR_RATION = 1.5;

    /**
     * Client UserAgent
     */
    const IDENTIFY_USER_AGENT = "BackQ\Nsq";

    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

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
        'logger' => null,
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

    const STATE_BINDWRITE = 1;
    const STATE_BINDREAD  = 2;
    const STATE_NOTHING   = 0;

    /**
     * @var IO\StreamIO
     */
    private $_io       = null;
    private $connected = false;

    private $state     = self::STATE_NOTHING;
    private $stateData = [];


    public function __construct(string $host = "127.0.0.1", int $port = 4150, array $config = [])
    {
        $this->config['host']     = $host;
        $this->config['port']     = $port;
        $this->config['clientId'] = gethostname() . '_' . getmypid();

        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    public function info($info) {
        if ($this->config['logger']          &&
            $this->config['logger'] != $this &&
            method_exists($this->config['logger'], __FUNCTION__)) {
            $this->config['logger']->info($info);
        }
    }

    public function error($msg) {
        if ($this->config['logger']          &&
            $this->config['logger'] != $this &&
            method_exists($this->config['logger'], __FUNCTION__)) {
            $this->config['logger']->error($msg);
        }
    }

    public function setWorkTimeout(int $seconds = null) {
        if ($seconds >= 1) {
            /**
             * 1000ms is minimum hearbeat
             */
            $this->config['heartbeat_interval_ms'] = intval($seconds * 1000);
        }
    }

    /**
     * Disconnects from queue
     */
    public function disconnect()
    {
        if (true === $this->connected) {
            try {
                if (self::STATE_BINDREAD == $this->state) {
                    /**
                     * When subscribed to receive new messages, nice way of closing the connection
                     * is "RDY 0" (pause messages) followed by "CLS" (cleanly close connection)
                     */
                    $this->writeCommand(self::PROTO_CLOSE);
                    $this->readSuccessResponse(self::RESPONSE_CLOSED);
                }
                $this->_io->close();
            } catch (\Exception $ex) {
                $this->error(__CLASS__ . ' ' . __FUNCTION__ . ': ' . $ex->getMessage());
            }

            $this->state     = self::STATE_NOTHING;
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
    public function ping($reconnect = true)
    {
        if ($this->connected && $this->_io) {
            return $this->_io->isSocketReady();
        }
        return false;
    }

    /**
     * After failed work processing
     *
     * @return bool
     */
    public function afterWorkFailed($workId)
    {
        if ($this->connected && self::STATE_BINDREAD == $this->state) {
            $this->writeCommand(sprintf(self::PROTO_REQUEUE, $workId, 0));
            return true;
        }
        return false;
    }

    /**
     * After successful work processing
     *
     * @return bool
     */
    public function afterWorkSuccess($workId)
    {
        if ($this->connected && self::STATE_BINDREAD == $this->state) {
            $this->writeCommand(sprintf(self::PROTO_FINISH, $workId));
            return true;
        }
        return false;
    }

    /**
     * Subscribe for new incoming data
     *
     * @return bool
     */
    public function bindRead($queue)
    {
        if ($this->connected && $this->state == self::STATE_NOTHING) {
            /**
             * A channel can, and generally does, have multiple clients connected.
             * Assuming all connected clients are in a state where they are ready to receive messages,
             * each message will be delivered to a random client
             */
            $this->writeCommand(sprintf(self::PROTO_SUBSCRIBE, $queue, $queue));
            $this->readSuccessResponse();

            $this->state = self::STATE_BINDREAD;
            $this->stateData = ['queue' => $queue,
                                'rdy'   => 0];
            return true;
        }
        return false;

    }

    /**
     * Prepare to write data into queue
     *
     * @return bool
     */
    public function bindWrite($queue)
    {
        if ($this->connected && $this->state == self::STATE_NOTHING) {
            $this->state = self::STATE_BINDWRITE;
            $this->stateData = ['queue' => $queue];
            return true;
        }
        return false;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * @deprecated
     * @return null|int
     */
    public function hasWorkers($queue = false)
    {
        $this->info(__CLASS__ . '.' . __FUNCTION__ . ' not supported');
        return true;
    }

    /**
     * Pick task from queue
     *
     * @param $timeout integer $timeout If given specifies number of seconds to wait for a job, '0' returns immediately
     * @return boolean|array [id, payload]
     */
    public function pickTask($timeout = null)
    {
        if ($timeout) {
            $this->info(__CLASS__ . '.' . __FUNCTION__ . ' arguments deprecated');
            $timeout = null;
        }

        if ($this->connected && self::STATE_BINDREAD == $this->state) {
            if ($this->stateData['rdy'] < 1) {
                $this->writeReady(1);
                $this->stateData['rdy'] = 1;
            }

            /**
             * This should return task or return notning after timeout
             */
            list($frameType, $messageFrame) = $this->readFrame(true);

            if ($frameType == self::RESPONSE_HEARTBEAT) {
                /**
                 * Manually handle heartbeats
                 * Hearbeats are breaks/timeouts in the pickTask cycle
                 */
                $this->writeCommand(self::PROTO_NOOP);
                return false;
            } else {

                if ($frameType !== self::FRAME_TYPE_MESSAGE) {
                    throw new RuntimeException(sprintf("was expecting a message frame, got '%s' with data '%s'",
                                                       $frameType,
                                                       $messageFrame));
                }

                $this->stateData['rdy'] = $this->stateData['rdy'] - 1;

                $message = substr($messageFrame, 26);
                $msgId   = substr($messageFrame, 10, 16);

                $time = floor(unpack("J", substr($messageFrame, 0, 8))[1]/1000000000);
                return [$msgId, $message, ['time' => DateTime::createFromFormat("U", $time)->format('c'),
                                           'attempts' => unpack("n", substr($messageFrame, 8, 2))[1]]];
            }
        }
        return false;
    }

    /**
     * Put task into queue
     *
     * @param  string $data The job body.
     * @return integer|boolean `false` on error otherwise an integer indicating
     *         the job id.
     */
    public function putTask($body, $params = array())
    {
        /**
         * @todo add support fot $params args
         */
        if ($this->connected && self::STATE_BINDWRITE == $this->state) {

            if (isset($params[self::PARAM_JOBTTR])) {
                if ($params[self::PARAM_JOBTTR] > $this->config['msg_timeout']) {
                    /**
                     * Too big TTR value for this worker
                     * The in-flight timeout expires and nsqd automatically re-queues the message.
                     * @see http://nsq.io/clients/building_client_libraries.html
                     */
                    throw new RuntimeException('Desired ' . self::PARAM_JOBTTR .
                                               ' param '  . $params[self::PARAM_JOBTTR] .
                                               '> ' . $this->config['msg_timeout'] . ' msg_timeout, ' .
                                               'NSQ expects answer within ' . $this->config['msg_timeout'] . ' seconds');
                }

                /**
                 * After 2 unanswered _heartbeat_ responses,
                 * nsqd will timeout and forcefully close a client connection that it has not heard from
                 * @see http://nsq.io/clients/building_client_libraries.html
                 */
                if ($this->config['heartbeat_interval_ms'] &&
                    (round($params[self::PARAM_JOBTTR] * 1000 * self::HEARTBEAT_TTR_RATION) >= round($this->config['heartbeat_interval_ms']))) {
                    throw new RuntimeException('Desired ' . self::PARAM_JOBTTR .
                                               ' param '  . $params[self::PARAM_JOBTTR] .
                                               '> ' . ($this->config['heartbeat_interval_ms'] / 1000) . ' (x' . self::HEARTBEAT_TTR_RATION . ' heartbeat), ' .
                                               'NSQ expects answer within two hearbeats, but cannot guarantee that, ' .
                                               'please configure your heartbeat_interval_ms to extend heartbeat intervals');
                }
            }

            if (isset($params[self::PARAM_READYWAIT])) {
                if ($this->config['max_req_timeout'] && ($params[self::PARAM_READYWAIT] > $this->config['max_req_timeout'])) {
                    /**
                     * Too big delay value for this server, value is configured on server side
                     * Preemptively decline sending jobs that server will reject
                     */
                    throw new RuntimeException('Desired ' . self::PARAM_READYWAIT . ' of ' .
                                               $params[self::PARAM_READYWAIT] . ' seconds is longer ' .
                                               'than specified server configured --max_req_timeout '  .
                                               'of ' . $this->config['max_req_timeout']);
                }
                /**
                 * Delay ready state by N seconds
                 * maximum value is limited to `nsqd --max-req-timeout` value
                 */
                $this->writeCommandWithBody(sprintf(self::PROTO_PUBDELAY, $this->stateData['queue'],
                                                    $params[self::PARAM_READYWAIT] * 1000),
                                            $body);
            } else {
                $this->writeCommandWithBody(sprintf(self::PROTO_PUBLISH, $this->stateData['queue']),
                                            $body);
            }
            $this->readSuccessResponse();
            return true;
        }
        return false;
    }

    /**
     * connect and negotiate protocol
     */
    public function connect()
    {
        if (isset($this->_io) || $this->connected) {
            $this->disconnect();
        }

        try {
            $this->_io = new IO\StreamIO($this->config['host'],
                                         $this->config['port'],
                                         $this->config['connection_timeout'],
                                         $this->config['stream_set_timeout'],
                                         null,
                                         true,
                                         $this->config['persistent']);
            $this->connected = true;

            $this->write(self::PROTO_VERSION);
            $this->writeIdentify();

        } catch (\Exception $ex) {
            $this->error($ex->getCode() . ': ' . $ex->getMessage());
        }
        return $this->connected;
    }

    /**
     * write the identify command to the connected socket, with out negotiate options
     */
    private function writeIdentify()
    {
        if ($this->state != self::STATE_NOTHING) {
            throw new RuntimeException('Incorrect protocol usage while ' . __FUNCTION__);
        }

        $identify = ["feature_negotiation" => true,
                     "client_id"   => $this->config['clientId'],
                     "hostname"    => gethostname(),
                     "user_agent"  => self::IDENTIFY_USER_AGENT,
                     'msg_timeout' => $this->config['msg_timeout'] * 1000];

        $identify["heartbeat_interval"] = $this->config['heartbeat_interval_ms'];

        $this->writeCommandWithBody(self::PROTO_IDENTIFY,
                                    json_encode($identify));

        list($frameType, $response) = $this->readFrame();
        if ($frameType !== self::FRAME_TYPE_RESPONSE) {
            throw new RuntimeException(sprintf("was expecting feature list response, got '%s' with data '%s'", $frameType, $response));
        }

        $features = @json_decode($response, true);
        if (!is_array($features)) {
            throw new RuntimeException(sprintf("was expecting feature list response, got '%s' with data '%s'", $frameType, $response));
        }

        if ($features['auth_required']) {
            if (empty($this->config['auth'])) {
                throw new RuntimeException("Authentication is required, but not provided in the config");
            }

            $this->writeCommandWithBody(self::PROTO_AUTH, $this->config['auth']);
            $this->authentication = $this->readAuthenticationHeader();
        }
    }

    /**
     * write the ready command to the connected socket - this sends the size of batch of messages the client can handle
     *
     * @param int number of messages to batch up and send to this client at once
     */
    private function writeReady($numberOfMessages) : bool
    {
        if ($this->connected && $this->state == self::STATE_BINDREAD) {
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
     */
    private function readSuccessResponse($expectedResponse = null)
    {
        list($frameType, $response) = $this->readFrame();
        if ($expectedResponse && $frameType === self::FRAME_TYPE_RESPONSE) {
            if ($expectedResponse === $response) {
                return;
            }
        }

        if ($frameType !== self::FRAME_TYPE_RESPONSE || $response !== self::RESPONSE_SUCCESS) {
            throw new RuntimeException(sprintf(__FUNCTION__ . " expecting Success, got '%s' with data '%s'", $frameType, $response));
        }
    }

    private function readAuthenticationHeader()
    {
        list($frameType, $response) = $this->readFrame();

        if ($frameType !== self::FRAME_TYPE_RESPONSE) {
            throw new RuntimeException('Authentication Failure:'.$response);
        }

        $authentication = @json_decode($response, true);
        if (!is_array($authentication)) {
            throw new RuntimeException(sprintf("was expecting authentication response, got '%s' with data '%s'", $frameType, $response));
        }

        return $authentication;
    }

    /**
     * generic helper function to write a command that had a body
     *
     * @param string $command the command name
     * @param string $body the command body to be sent with the command
     */
    private function writeCommandWithBody($command, $body)
    {
        $length = pack("N", strlen($body));

        $this->writeCommand($command);
        $this->write($length.$body);
    }

    /**
     * write command w/o body
     * @param string $command
     */
    private function writeCommand($cmd)
    {
        $this->write($cmd . "\n");
    }

    /**
     * generic command to write a buffer to the socket and make sure nothing bad happens in the process
     *
     * @param string $buffer the full buffer to write to the socket
     */
    private function write($buffer)
    {
        $this->info('--> writing ' . trim($buffer));
        $this->_io->write($buffer);
    }

    /**
     * @param bool $raw return raw frame whatever kind of frame it is
     *
     * @return array
     */
    private function readFrame($raw = false)
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

            $this->info("received frameType=" . $frameType);
            if ($raw) {
                break;

            } else {
                if ($frameData != self::RESPONSE_HEARTBEAT) {
                    break;
                } else {
                    $this->writeCommand(self::PROTO_NOOP);
                }
            }
        }
        return [$frameType, $frameData];
    }

    /**
     * read and unpack a 32bit binary INT
     */
    private function readInt()
    {
        $bytes = $this->read(4);
        return unpack("N", $bytes)[1];
    }

    /**
     * read from the socket a set size of data
     */
    private function read($size)
    {
        $this->info('--> reading ' . $size . ' bytes');
        $result = $this->_io->read($size);
        if ($size != strlen($result)) {
            throw new \RuntimeException('Failed to read ' . $size . ' bytes from IO');
        }
        $this->info('<-- reading ' . $size . ' bytes');
        return $result;
    }
}
