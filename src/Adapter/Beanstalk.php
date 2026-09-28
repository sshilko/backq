<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use BackQ\Adapter\Beanstalk\Client;
use BackQ\Adapter\Beanstalk\Connection;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;
use function is_array;

/**
 * Beanstalk protocol adapter
 *
 * Every message is reported through $this?->logger. Psalm reads the nullsafe operator as
 * widening $this to `Beanstalk|null` for the rest of the method, which is not what it
 * means on an injected logger, so the resulting issue is silenced for this class.
 * @psalm-suppress TypeDoesNotContainNull
 *
 * @see https://raw.githubusercontent.com/kr/beanstalkd/master/doc/protocol.txt
 */
class Beanstalk extends AbstractAdapter
{
    public const string ADAPTER_NAME = 'beanstalk';

    public const int PRIORITY_DEFAULT = 1024;

    public const int JOBTTR_DEFAULT = 60;

    private Client $client;

    /**
     * Whether the client is built and the socket is open
     */
    private bool $connected = false;

    /**
     * Timeout for reserve() command
     *
     */
    private ?int $workTimeout = null;

    /**
     * @param LoggerInterface $logger the logger that receives the adapter messages
     */
    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    /**
     * Connects adapter
     *
     * @param Connection $connection where to open the socket, and how
     */
    #[Override]
    public function connect(Connection $connection = new Connection()): bool
    {
        if (true === $this->connected) {
            return true;
        }

        $connection = $this->connection($connection);

        return $this->attemptConnect(__FUNCTION__, function () use ($connection): bool {
            $this->client = new Client([
                'host' => $connection->host,
                'port' => $connection->port,
                'timeout' => $connection->timeout,
                'persistent' => $connection->persistent,
                'context' => $connection->context,
                /**
                 * The vendored client reports through whatever answers error(string), which
                 * any PSR-3 logger already does. This is that logger.
                 */
                'logger' => $this->logger,
            ]);

            if ($this->client->connect()) {
                $this->connected = true;

                return true;
            }

            return false;
        });
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        $this->workTimeout = $seconds;
    }

    /**
     * Checks (if possible) if there are workers to work immediately
     *
     */
    #[Override]
    public function hasWorkers(string $queue = ''): bool
    {
        return $this->attempt(__FUNCTION__, function () use ($queue): bool {
            if ($queue) {
                /**
                 * Workers watching queue
                 *
                 * rarely fails with NOT_FOUND even when we binded (use %tube) successfuly before
                 * failure produces error-log entries
                 */
                /**
                 * @var array<array-key, mixed>|false
                 */
                $result = $this->client->statsTube($queue);

                return is_array($result) && isset($result['current-watching'])
                    ? $result['current-watching'] > 0
                    : false;
            }

            /**
             * Workers at all connected (not very usefull)
             */
            /**
             * @var array<array-key, mixed>|false
             */
            $result = $this->client->stats();

            return is_array($result) && isset($result['current-workers'])
                ? $result['current-workers'] > 0
                : false;
        });
    }

    /**
     * Returns TRUE if connection is alive
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        return $this->attempt(__FUNCTION__, function () use ($reconnect): bool {
            /**
             * @todo Any other fast && reliable options to check if socket is alive?
             */
            /**
             * @var array<array-key, mixed>|false $result
             */
            $result = $this->client->stats();
            if (false !== $result) {
                return true;
            }

            if ($reconnect && true === $this->client->connect()) {
                return false !== $this->client->stats();
            }

            return false;
        });
    }

    /**
     * Subscribe for new incoming data
     *
     */
    #[Override]
    public function bindRead(string $queue): bool
    {
        /**
         * watch() answers the number of tubes now watched, which is an int on success
         */
        return $this->attempt(
            __FUNCTION__,
            function () use ($queue): bool {
                return (bool) $this->client->watch($queue);
            }
        );
    }

    /**
     * Prepare to write data into queue
     *
     */
    #[Override]
    public function bindWrite(string $queue): bool
    {
        /**
         * useTube() answers the tube name, which is a string on success
         */
        return $this->attempt(
            __FUNCTION__,
            function () use ($queue): bool {
                return (bool) $this->client->useTube($queue);
            }
        );
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
        $result = $this->attemptRethrowing(__FUNCTION__, function () use ($timeout): bool|array {
            $reserved = $this->client->reserve($timeout ?? $this->workTimeout);
            /**
             * @var array{id: int, body: string|false}|false $reserved
             */
            if (is_array($reserved)) {
                return [$reserved['id'], $reserved['body'], []];
            }

            return false;
        });
        \assert(\is_bool($result) || \is_array($result));

        return $result;
    }

    /**
     * Put task into queue
     *
     * @param string|Stringable $body     The job body.
     * @param int               $readyWait Seconds the job may sit in the queue before a worker may take it.
     * @param int|null          $jobTtr    Seconds a reserved job may run before it is released again.
     * @param int|null          $priority  Lower runs first, 1024 is the beanstalkd default.
     *
     * @return string|Throwable the job id, or the failure
     */
    #[Override]
    public function putTask(
        string|Stringable $body,
        int $readyWait = 0,
        ?int $jobTtr = null,
        ?int $priority = null,
    ): string|Throwable {
        return $this->attemptReturning(__FUNCTION__, function () use ($body, $readyWait, $jobTtr, $priority): string {
            $result = $this->client->put(
                $priority ?? self::PRIORITY_DEFAULT,
                $readyWait,
                $jobTtr ?? self::JOBTTR_DEFAULT,
                (string) $body
            );

            /**
             * The policy helper names the class and the operation when it logs this, so the
             * reason is all the message has to carry.
             */
            if (false === $result) {
                throw new RuntimeException('beanstalkd rejected the job');
            }

            return (string) $result;
        });
    }

    /**
     * After failed work processing
     *
     */
    #[Override]
    public function afterWorkFailed(?string $workId): bool
    {
        /**
         * Release task back to queue with default priority and 1 second ready-delay
         * The client documents an integer id, the acknowledge contract a string,
         * so the id changes hand on the way out
         */
        return $this->attempt(
            __FUNCTION__,
            function () use ($workId): bool {
                return true === $this->client->release((int) $workId, self::PRIORITY_DEFAULT, 1);
            }
        );
    }

    /**
     * After successful work processing
     *
     */
    #[Override]
    public function afterWorkSuccess(?string $workId): bool
    {
        return $this->attempt(
            __FUNCTION__,
            function () use ($workId): bool {
                return true === $this->client->delete((int) $workId);
            }
        );
    }

    /**
     * Disconnects from queue
     *
     */
    #[Override]
    public function disconnect(): bool
    {
        return $this->attempt(__FUNCTION__, function (): bool {
            $this->client->disconnect();
            $this->connected = false;

            return true;
        });
    }

    /**
     * The beanstalkd client is only usable once connect() answered
     */
    #[Override]
    protected function isReady(): bool
    {
        return true === $this->connected;
    }

    /**
     * The connection the client is about to be built from
     *
     * PersistentBeanstalk answers a persistent one, which is the whole difference between
     * the two adapters: everything else lives in this class.
     *
     * @param Connection $connection the connection the caller asked for
     */
    protected function connection(Connection $connection): Connection
    {
        return $connection;
    }
}
