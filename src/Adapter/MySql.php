<?php

declare(strict_types=1);

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use BackQ\Adapter\MySql\JobColumn;
use BackQ\Adapter\MySql\JobConfig;
use BackQ\Adapter\MySql\JobState;
use InvalidArgumentException;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Override;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use function count;
use function date;
use function json_encode;
use function max;
use function usleep;
use const MYSQLI_ASSOC;

/**
 * This adapter uses persistent MySQL table for job queue:
 *
 * ------------------------------
 * CREATE TABLE `backq_jobs` (
 * `id` int(10) unsigned NOT NULL,
 * `payload` json NOT NULL,
 * `sync` enum('WAIT', 'LOCK', 'DONE', 'HOLD') DEFAULT 'WAIT' NOT NULL,
 * `time_sync` timestamp NOT NULL,
 * PRIMARY KEY (`id`),
 * KEY (`sync`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ------------------------------
 *
 * Jobs are picked sync=WAIT
 * Jobs are moved to sync=DONE
 * Jobs are not deleted after being processed
 * Jobs that failed moved to sync=HOLD
 * Jobs currently in processing moved to sync=LOCK
 *
 * WAIT->LOCK->[DONE | HOLD]
 *
 * The adapter talks to a plain mysqli link owned by the caller, the link has to
 * be established before a job is published or picked. Query failures surface as
 * mysqli_sql_exception, the default error mode of the driver since PHP 8.1
 *
 * Class MySql
 * @package ns\BackQ\Adapter
 */
abstract class MySql extends AbstractAdapter
{
    /**
     * @param mysqli $db an established connection, the adapter never closes it
     * @param JobConfig $config the job table and the sleeps of this adapter
     * @param LoggerInterface|null $logger the logger that receives the adapter messages
     */
    public function __construct(protected mysqli $db, protected JobConfig $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        /**
         * Nothing else to set up, the connection and the config are injected
         */
    }

    /**
     * return false|array [id, payload]
     */
    #[Override]
    public function pickTask(?int $timeout = null): bool|array
    {
        try {
            $this->db->begin_transaction();
            $sql = 'SELECT ' . $this->config->idColumn . ', ' .
                $this->config->dataColumn . ' ' .
                'FROM ' . $this->config->table . ' ' .
                'WHERE ' . JobColumn::State->value . " = '" . JobState::Wait->value . "' " .
                'LIMIT 1 ' .
                'FOR UPDATE';
            $this->logDebug(__FUNCTION__ . ': ' . $sql);
            $data = $this->select($sql);
            \assert(isset($data[0]));
            if (1 === count($data)) {
                $jobId = $data[0][$this->config->idColumn];
                $sql = 'UPDATE ' . $this->config->table . ' ' .
                    'SET ' . JobColumn::State->value . ' = "' . JobState::Lock->value . '", ' .
                    JobColumn::Time->value . ' = "' . date('Y-m-d H:i:s') . '" ' .
                    'WHERE ' . $this->config->idColumn . ' = ' . $jobId;
                $this->logDebug($sql);
                $this->write($sql);
                $result = [$jobId, $data[0][$this->config->dataColumn]];
                $this->logDebug(__FUNCTION__ . ' result: ' . (string) json_encode($result));
                $this->db->commit();
                usleep($this->config->pickSuccessSleep);

                return $result;
            }
        } catch (\Throwable $e) {
            $this->logError($e->getMessage());
            $this->rollback();
        }
        usleep($this->config->pickMissSleep);

        return false;
    }

    /**
     * Put task into the jobs table
     *
     * $jobId is nullable only because PHP forbids appending a required parameter to an inherited
     * method. A call without a usable id is a caller bug and comes back as an InvalidArgumentException.
     * Callers that always hold an id should use putTaskTo() instead.
     *
     * @param  string|Stringable  $body      The job body.
     * @param  int|string|null    $jobId     The id the row is stored under. It is the primary key, so it decides the row.
     * @param  bool               $putAsDone Store the row as already done instead of waiting.
     * @param  bool               $noSleep   Skip the throttle that keeps a hot loop off the database.
     *
     * @return string|Throwable the job id, or the failure
     */
    #[Override]
    public function putTask(
        string|Stringable $body,
        int|string|null $jobId = null,
        bool $putAsDone = false,
        bool $noSleep = false,
    ): string|Throwable {
        if (null === $jobId || '' === (string) $jobId || 0 === $jobId) {
            $this->logError(__FUNCTION__ . ' Missing job id parameter');

            return new InvalidArgumentException(
                self::class . '::' . __FUNCTION__ . '() requires a job id, pass $jobId or call putTaskTo()'
            );
        }

        if (!$noSleep) {
            usleep($this->config->putTaskSleep);
        }

        $state = $putAsDone ? JobState::Done : JobState::Wait;

        try {
            $this->db->begin_transaction();
            $sql = 'SELECT ' . $this->config->idColumn . ' ' .
                'FROM ' . $this->config->table . ' ' .
                'WHERE ' . $this->config->idColumn . " = '" . $this->escape($jobId) . "' " .
                'FOR UPDATE';

            $this->logDebug(__FUNCTION__ . ': ' . $sql);
            $data = $this->select($sql);
            \assert(isset($data[0]));
            if (1 === count($data)) {
                $sql = 'UPDATE ' . $this->config->table . ' ' .
                    'SET ' . $this->config->dataColumn . ' = "' . $this->escape((string) $body) . '", ' .
                    JobColumn::Time->value . ' = "' . date('Y-m-d H:i:s') . '", ' .
                    JobColumn::State->value . ' = "' . $state->value . '" ' .
                    'WHERE ' . $this->config->idColumn . ' = "' . $this->escape($jobId) . '"';
                $this->logDebug($sql);
                $this->write($sql);
                $this->db->commit();

                return (string) $jobId;
            }

            $sql = 'INSERT INTO ' . $this->config->table . ' ' .
                '(' . $this->config->idColumn . ',' . $this->config->dataColumn . ','
                . JobColumn::Time->value . ',' . JobColumn::State->value . ')' .
                ' VALUES ' .
                '(' . '"' . $this->escape($jobId) . '",' .
                '"' . $this->escape((string) $body) . '",' .
                '"' . date('Y-m-d H:i:s') . '",' .
                '"' . $state->value . '")';
            $this->logDebug($sql);
            $this->write($sql);
            $this->db->commit();

            return (string) $jobId;
        } catch (Throwable $e) {
            $this->logError($e->getMessage());
            $this->rollback();

            return $e;
        }
    }

    /**
     * Put task into the jobs table under a known id
     *
     * The mandatory-argument form of putTask(), for callers that always hold an id.
     *
     * @param int|string         $jobId
     * @param string|Stringable  $body
     * @param bool               $putAsDone
     * @param bool               $noSleep
     *
     * @return string|Throwable the job id, or the failure
     */
    public function putTaskTo(
        int|string $jobId,
        string|Stringable $body,
        bool $putAsDone = false,
        bool $noSleep = false,
    ): string|Throwable {
        return $this->putTask($body, $jobId, $putAsDone, $noSleep);
    }

    #[Override]
    public function afterWorkSuccess(int|string|null $workId): bool
    {
        return $this->updateState(JobState::Done, $workId);
    }

    #[Override]
    public function afterWorkFailed(int|string|null $workId): bool
    {
        return $this->updateState(JobState::Hold, $workId);
    }

    /**
     * A healthy link is all this adapter needs, the job table is the queue
     *
     * The reconnect flag is ignored, the mysqli driver cannot reconnect:
     * a dead link can only be replaced by a new mysqli built by the caller
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        try {
            return $this->db->ping();
        } catch (mysqli_sql_exception) {
            return false;
        }
    }

    #[Override]
    public function hasWorkers(string $queue): bool
    {
        return true;
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        /**
         * Idle timeouts are a worker concern, the queue is shared via the table
         */
    }

    #[Override]
    public function connect(): bool
    {
        return $this->ping();
    }

    /**
     * The connection is owned by the caller, it outlives the adapter
     */
    #[Override]
    public function disconnect(): bool
    {
        return true;
    }

    #[Override]
    public function bindRead(string $queue): bool
    {
        return true;
    }

    #[Override]
    public function bindWrite(string $queue): bool
    {
        return true;
    }

    /**
     * Move a job into the given state
     */
    private function updateState(JobState $state, int|string|null $workId): bool
    {
        if (null === $workId) {
            $this->logError(__FUNCTION__ . ' Missing job id');

            return false;
        }

        $sql = 'UPDATE ' . $this->config->table . ' ' .
            'SET ' . JobColumn::State->value . ' = "' . $state->value . '" ' .
            'WHERE ' . $this->config->idColumn . ' = "' . $this->escape($workId) . '"';

        $this->logDebug(__FUNCTION__ . ': ' . $sql);

        try {
            return 0 <= $this->write($sql);
        } catch (mysqli_sql_exception $e) {
            /**
             * An acknowledge must not throw, false tells the worker the job stays open
             */
            $this->logError($e->getMessage());

            return false;
        }
    }

    /**
     * Run a read statement
     *
     * @return array<mixed> rows as column => value maps
     */
    private function select(string $sql): array
    {
        $result = $this->db->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * Run a write statement
     *
     * @return int the number of rows the statement matched, 0 if the statement is not a write
     * @throws mysqli_sql_exception when the server rejects the statement
     */
    private function write(string $sql): int
    {
        $result = $this->db->query($sql);
        if ($result instanceof mysqli_result) {
            $result->free();

            return 0;
        }

        return max(0, (int) $this->db->affected_rows);
    }

    private function escape(int|string $value): string
    {
        return $this->db->real_escape_string((string) $value);
    }

    /**
     * Give up on the current transaction, the connection may be gone already
     */
    private function rollback(): void
    {
        try {
            $this->db->rollback();
        } catch (mysqli_sql_exception) {
            $this->logError(__FUNCTION__ . ' Transaction could not be rolled back');
        }
    }
}
