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
 * Beanstalk protocol adapter
 *
 * @see https://raw.githubusercontent.com/kr/beanstalkd/master/doc/protocol.txt
 */
final class Beanstalk extends AbstractAdapter
{
    const ADAPTER_NAME = 'beanstalk';

    const PARAM_PRIORITY  = 'priority';
    const PARAM_READYWAIT = 'readywait';
    const PARAM_JOBTTR    = 'jobttr';

    private $client;
    private $connected;

    /**
     * Simple log
     */
    public function error($msg)
    {
        @error_log('beanstalk adapter error: ' . $msg);
    }

    /**
     * Connects adapter
     *
     * @return bool
     */
    public function connect($host = '127.0.0.1', $port = 11300, $timeout = 1, $persistent = false)
    {
        try {
            $bconfig = array('host' => $host, 'port' => $port, 'timeout' => $timeout, 'persistent' => $persistent);
            $bconfig['logger'] = $this;
            $this->client = new \Beanstalk\Client($bconfig);
            if ($this->client->connect()) {
                $this->connected = true;
                return true;
            }
        } catch (Exception $e) {
            @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * @return null|int
     */
    public function hasWorkers($queue)
    {
        if ($this->connected) {
            try {
                $result = $this->client->stats($queue);
                if ($result && is_array($result) && isset($result['current-workers'])) {
                    return $result['current-workers'];
                }
            } catch (RuntimeException $e) {}
        }
    }

    /**
     * Returns TRUE if connection is alive
     */
    public function ping($reconnect = true)
    {
        try {
            /**
             * @todo Any other fast && reliable options to check if socket is alive?
             */
            $result = $this->client->stats();
            if ($result) {
                return true;
            } elseif ($reconnect) {
                if (true == $this->client->connect()) {
                    return $this->ping(false);
                }
            }
        } catch (RuntimeException $e) {}
    }

    /**
     * Subscribe for new incoming data
     *
     * @return bool
     */
    public function bindRead($queue)
    {
        if ($this->connected) {
            try {
                if ($this->client->watch($queue)) {
                    return true;
                }
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
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
        if ($this->connected) {
            try {
                $this->client->useTube($queue);
                return true;
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Pick task from queue
     *
     * @return boolean|array [id, payload]
     */
    public function pickTask()
    {
        if ($this->connected) {
            try {
                $result = $this->client->reserve();
                if (is_array($result)) {
                    return array($result['id'], $result['body']);
                }
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Pick task from queue
     *
     * @param  string $data The job body.
     * @return integer|boolean `false` on error otherwise an integer indicating
     *         the job id.
     */
    public function putTask($body, $params = array())
    {
        if ($this->connected) {
            try {

                $priority  = 1024;
                $readywait = 0;
                $jobttr    = 60;

                if (isset($params[self::PARAM_PRIORITY])) {
                    $priority  = $params[self::PARAM_PRIORITY];
                }

                if (isset($params[self::PARAM_READYWAIT])) {
                    $readywait = $params[self::PARAM_READYWAIT];
                }

                if (isset($params[self::PARAM_JOBTTR])) {
                    $jobttr    = $params[self::PARAM_JOBTTR];
                }

                $result = $this->client->put($priority, $readywait, $jobttr, $body);

                if (false != $result) {
                    return $result;
                }
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
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
        if ($this->connected) {
            try {
                if ($this->client->release($workId)) {
                    return true;
                }
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
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
        if ($this->connected) {
            try {
                if ($this->client->delete($workId)) {
                    return true;
                }
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Disconnects from queue
     *
     * @return bool
     */
    public function disconnect()
    {
        if (true === $this->connected) {
            try {
                $this->client->disconnect();
                $this->connected = false;
                return true;
            } catch (Exception $e) {
                @error_log('Beanstalk adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
            }
        }
        return false;
    }

}
