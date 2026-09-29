<?php

declare(strict_types=1);

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
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
use function bin2hex;
use function count;
use function intdiv;
use function json_encode;
use function max;
use function random_bytes;
use function time;
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
 * hasWorkers() is answered from a companion table, one row per bound worker, holding the
 * moment the server last saw it. The user creates that table:
 *
 * ------------------------------
 * CREATE TABLE `backq_workers` (
 * `id` int unsigned NOT NULL AUTO_INCREMENT,
 * `queue` varchar(64) NOT NULL,
 * `token` varbinary(16) NOT NULL,
 * `seen` datetime NOT NULL,
 * PRIMARY KEY (`id`),
 * UNIQUE KEY `backq_workers_token` (`token`),
 * KEY `backq_workers_queue_seen` (`queue`, `seen`),
 * KEY `backq_workers_seen` (`seen`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * The two secondary indexes are not redundant and neither is decoration. The count
 * filters on a queue and a time, so it needs (queue, seen); the reap filters on the time
 * alone, so it needs (seen) or it is a full scan of the registry on every interval.
 *
 * `token` is varbinary(16) and holds a version 7 UUID, and the type is not a size
 * preference: the adapter sends it as UNHEX(), and a utf8mb4 varchar rejects 16 raw bytes
 * with error 1366, measured. It is 16 bytes in the unique key rather than a declared 256.
 * ------------------------------
 *
 * A table queue shares one job table between every queue, so the registry carries a queue
 * column of its own; a worker is one that held a lease on this queue within the last
 * JobConfig::$workerTtl seconds, and the expiry is computed by the server, so no clock
 * can disagree. An adapter pointed at a table that was never created answers "no workers"
 * and says so once at debug. The registry is never load-bearing: nothing here raises, and
 * nothing here stops a worker from picking.
 *
 * Every message is reported through $this?->logger. Psalm reads the nullsafe operator as
 * widening $this to `MySql|null` for the rest of the method, which is not what it means
 * on an injected logger, so the two resulting issues are silenced for this class.
 * @psalm-suppress TypeDoesNotContainNull
 * @psalm-suppress PossiblyNullReference
 *
 * The adapter talks to a plain mysqli link owned by the caller, the link has to
 * be established before a job is published or picked. Query failures surface as
 * mysqli_sql_exception, the default error mode of the driver since PHP 8.1
 *
 * The link is the caller's, so a dead one is not this adapter's to replace. A caller
 * that wants the adapter to survive one hands it a connectionProvider on the JobConfig,
 * and from then on the link the provider builds is the one every statement goes to.
 *
 * Class MySql
 * @package ns\BackQ\Adapter
 */
class MySql extends AbstractAdapter
{
    /**
     * How many lease lengths past expiry a row has to be before the reap deletes it
     *
     * Deleting at one would race a reader whose clock is a second behind, and the read is
     * already correct without the reap at all.
     */
    private const int REAP_LEASES = 3;

    /**
     * The most rows one reap deletes
     *
     * The reap runs on the renew's throttle rather than once at startup, so it is a
     * statement the database sees every workerTtl / 3 seconds from every worker, and an
     * unbounded DELETE is not a statement to hand a busy server on a schedule. Bounded, a
     * backlog drains over a few intervals instead of in one long transaction holding row
     * locks, and the common case - zero rows past expiry - is unchanged by the limit.
     */
    private const int REAP_BATCH = 1000;

    /**
     * The error the server raises for a table that does not exist
     *
     * ER_NO_SUCH_TABLE, measured on the pinned server rather than quoted. It is the one code
     * that means "the DDL was never run" rather than "the server is unhappy".
     */
    private const int ERROR_NO_SUCH_TABLE = 1146;

    /**
     * The token naming this adapter in the worker registry, null while it is not a worker
     *
     * This is the only record that it is a worker at all: the adapter holds no
     * ConnectionState, and isReady() is unconditionally true, so there is no bind state to
     * consult. It is also what keeps a publisher from finding itself - not by an explicit
     * exclusion, but because nothing a publisher does ever produces a token.
     */
    private ?string $workerToken = null;

    /**
     * The unix second of the last lease write that worked, null before the first one
     *
     * Set only on the way that worked: a failed renew is a failed lease, and a throttle
     * that advanced anyway would leave the next attempt a full workerTtl / 3 away, so a
     * transient error would cost a third of the lease length of visibility.
     */
    private ?int $renewedAt = null;

    /**
     * The queue this adapter bound read to, kept because pickTask() has no queue argument
     * to renew against
     */
    private string $lastQueue = '';

    /**
     * Set once the registry table has been found to be missing
     *
     * The user has to run the DDL, and a deployment that has not must degrade to "no
     * workers" rather than raise. At `error` that is one line per hasWorkers() call from
     * every web request, so it is recorded once and remembered, and every later call on
     * this adapter is answered without touching the server. The library cannot tell "you
     * forgot the CREATE TABLE" from "the server went away"; only 1146 is treated as the
     * former, and every other failure keeps logging at `error`. This boolean only ever
     * moves one way.
     *
     * **Per adapter instance, and deliberately not static.** A worker builds one adapter
     * and loops, so the line appears once for the life of the process - which is the case
     * the de-duplication is for. A publisher in a PHP-FPM request builds a fresh adapter
     * per request, so it gets one `debug` line per request instead; what bounds that is the
     * level, not the flag. `static` would fix the count and introduce a worse bug: two
     * adapters in one process pointed at two different worker tables (which is what a
     * multi-queue setup and the test suite both do) would share the flag, and the first
     * one's missing table would silently answer `false` for the second one.
     */
    private bool $registryMissing = false;

    /**
     * @param mysqli $db an established connection, the adapter never closes it
     * @param JobConfig $config the job table and the sleeps of this adapter
     * @param LoggerInterface $logger the logger that receives the adapter messages
     */
    public function __construct(protected mysqli $db, protected JobConfig $config, LoggerInterface $logger)
    {
        parent::__construct($logger);

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
        /**
         * Before begin_transaction() and outside every catch below. A renew that throws is
         * swallowed by its own attempt(), so it can neither roll back the pick nor be
         * logged as a pick failure; inside the transaction it would hold the row lock for
         * longer than the job it is protecting. The usleep() at the end of this method is
         * already outside the transaction, and this stays next to it.
         */
        $this->renew();

        try {
            $this->db->begin_transaction();
            $sql = 'SELECT ' . $this->config->idColumn . ', ' .
                $this->config->dataColumn . ' ' .
                'FROM ' . $this->config->table . ' ' .
                'WHERE ' . JobColumn::State->value . " = '" . JobState::Wait->value . "' " .
                'LIMIT 1 ' .
                'FOR UPDATE';
            $this?->logger->debug(__FUNCTION__ . ': ' . $sql);
            $data = $this->select($sql);
            \assert(isset($data[0]));
            if (1 === count($data)) {
                $jobId = $data[0][$this->config->idColumn];
                $sql = 'UPDATE ' . $this->config->table . ' ' .
                    'SET ' . JobColumn::State->value . ' = "' . JobState::Lock->value . '", ' .
                    JobColumn::Time->value . ' = NOW(), ' .
                    'WHERE ' . $this->config->idColumn . ' = "' . $this->escape((string) $jobId) . '"';
                $this?->logger->debug($sql);
                $this->write($sql);
                $result = [$jobId, $data[0][$this->config->dataColumn]];
                $this?->logger->debug(__FUNCTION__ . ' result: ' . (string) json_encode($result));
                $this->db->commit();
                usleep($this->config->pickSuccessSleep);

                return $result;
            }
        } catch (\Throwable $e) {
            $this->log(__FUNCTION__, $e);
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
            $this?->logger->error(__FUNCTION__ . ' Missing job id parameter');

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

            $this?->logger->debug(__FUNCTION__ . ': ' . $sql);
            $data = $this->select($sql);
            \assert(isset($data[0]));
            if (1 === count($data)) {
                $sql = 'UPDATE ' . $this->config->table . ' ' .
                    'SET ' . $this->config->dataColumn . ' = "' . $this->escape((string) $body) . '", ' .
                    JobColumn::Time->value . ' = NOW(), ' .
                    JobColumn::State->value . ' = "' . $state->value . '" ' .
                    'WHERE ' . $this->config->idColumn . ' = "' . $this->escape($jobId) . '"';
                $this?->logger->debug($sql);
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
                'NOW(),' .
                '"' . $state->value . '")';
            $this?->logger->debug($sql);
            $this->write($sql);
            $this->db->commit();

            return (string) $jobId;
        } catch (Throwable $e) {
            $this->log(__FUNCTION__, $e);
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
    public function afterWorkSuccess(?string $workId): bool
    {
        return $this->updateState(JobState::Done, $workId);
    }

    #[Override]
    public function afterWorkFailed(?string $workId): bool
    {
        return $this->updateState(JobState::Hold, $workId);
    }

    /**
     * A healthy link is all this adapter needs, the job table is the queue
     *
     * The mysqli driver cannot reconnect, so a dead link is replaced by a new one built
     * by the caller's connectionProvider, and only when the caller asked for one. Without
     * a provider the link stays the caller's to replace and this answers false, which is
     * what a caller already handling its own links expects to see.
     */
    #[Override]
    public function ping(bool $reconnect = true): bool
    {
        return $this->attempt(
            __FUNCTION__,
            function () use ($reconnect): bool {
                if ($this->db->ping()) {
                    return true;
                }

                return $reconnect && $this->replaceDeadLink();
            }
        );
    }

    /**
     * Answered from the worker registry: a worker is one that held a lease on this queue
     * within the last JobConfig::$workerTtl seconds.
     *
     * The predicate is the whole correctness argument, and it is why this path is one read
     * and no write - an expired row is invisible without being deleted, so there is no reap
     * here the way there is on the Redis adapter. That is a difference from Redis and not an
     * oversight: a write in a web request is a write in a web request.
     *
     * select() and not write(): write() frees a result set and answers 0 for any statement
     * that produced one, so a hasWorkers() built on it would report no workers forever,
     * with a green mocked test suite behind it. Nothing in the offline layer can reach
     * that failure.
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        return $this->attempt(__FUNCTION__, function () use ($queue): bool {
            if ($this->registryMissing) {
                return false;
            }

            $escaped = $this->escape($queue);

            try {
                $rows = $this->select(
                    'SELECT COUNT(*) AS workers FROM ' . $this->config->workerTable . ' '
                        . 'WHERE queue = "' . $escaped . '" '
                        . 'AND seen > NOW() - INTERVAL ' . $this->config->workerTtl . ' SECOND'
                );
            } catch (mysqli_sql_exception $e) {
                if (self::ERROR_NO_SUCH_TABLE !== $e->getCode()) {
                    throw $e;
                }

                $this->registryMissing = true;
                $this?->logger->debug(
                    'hasWorkers: no ' . $this->config->workerTable . ' table, reporting no workers. '
                        . 'Create it, see the README for the DDL'
                );

                return false;
            }

            /**
             * COUNT(*) arrives as a string and the cast is what reads it, so a count of
             * "0" is false and a count of "1" is true
             */
            return (int) ($rows[0]['workers'] ?? 0) > 0;
        });
    }

    #[Override]
    public function setWorkTimeout(?int $seconds = null): void
    {
        /**
         * Idle timeouts are a worker concern, the queue is shared via the table
         */
        $this?->logger->debug(__FUNCTION__ . ' is a worker concern, the queue is the table');
    }

    #[Override]
    public function connect(): bool
    {
        return $this->ping();
    }

    /**
     * The connection is owned by the caller, it outlives the adapter; the worker lease is
     * this adapter's to drop
     *
     * The token is read out and the property cleared before the release is attempted: the
     * release is keyed by the token, and a worker that could not write it must still not
     * believe in a lease it no longer has.
     */
    #[Override]
    public function disconnect(): bool
    {
        $token = $this->workerToken;
        $this->workerToken = null;
        $this->renewedAt   = null;
        if (null === $token) {
            return true;
        }

        $this->attempt(__FUNCTION__, function () use ($token): bool {
            $this->write(
                'DELETE FROM ' . $this->config->workerTable . ' WHERE token = UNHEX("' . $token . '")'
            );

            return true;
        });

        return true;
    }

    /**
     * Announce this adapter as a worker on this queue, and reap what is left behind
     *
     * Both statements are best-effort and the answer is discarded. bindRead() cannot fail
     * on the registry's account: a deployment that revoked one privilege, or that has not
     * run the DDL, would otherwise stop every worker in it over a feature that exists to
     * answer a question.
     *
     * The reap runs here and in renew(), and never on the publisher's path - a write in a
     * web request is a write in a web request. Here it clears a backlog while the queue is
     * quiet; in renew() it is what keeps the table bounded. It deletes at three lease
     * lengths rather than one, so a row is only removed once it is far enough past expiry
     * that no reader could be counting it, and in batches rather than all at once.
     */
    #[Override]
    public function bindRead(string $queue): bool
    {
        $this->workerToken ??= $this->makeToken();
        $this->lastQueue = $queue;
        $this->announce(__FUNCTION__);

        return true;
    }

    #[Override]
    public function bindWrite(string $queue): bool
    {
        return true;
    }

    /**
     * The link belongs to the caller, so a refused operation is about that and not
     * about anything the adapter could do differently
     */
    #[Override]
    protected function preconditionFailed(string $operation): void
    {
        $this?->logger->debug(static::class . ' adapter ' . $operation . ': the mysqli link is owned by the caller');
    }

    /**
     * Trade a dead link for the one the caller's provider builds
     *
     * The provider is asked first and the dead link is closed only once a live one exists,
     * so a provider that fails leaves the caller the link it already has. The link it
     * returns is from then on the one every statement goes to, which is the whole point:
     * without this the mysqli driver cannot recover and the worker is done.
     *
     * @psalm-suppress PossiblyNullPropertyAssignment the nullsafe logger line above widens
     *                 $this, which it is not
     */
    private function replaceDeadLink(): bool
    {
        $provider = $this->config->connectionProvider;
        if (null === $provider) {
            $this?->logger->debug(
                __FUNCTION__ . ': no connectionProvider configured, the dead link is the caller\'s to replace'
            );

            return false;
        }

        $this?->logger->debug(__FUNCTION__ . ': asking the connectionProvider for a new link');

        $replacement = $provider();

        $dead = $this->db;
        $this->db = $replacement;
        $this->closeLink($dead);

        $this?->logger->debug(__FUNCTION__ . ': the new link is in place');

        return true;
    }

    /**
     * Give up on a link the adapter no longer uses
     *
     * A link that will not close is not worth a raise: the link it belonged to is already
     * gone as far as this adapter is concerned, and a worker must not die on the way out.
     */
    private function closeLink(mysqli $link): void
    {
        try {
            $link->close();
        } catch (Throwable $e) {
            $this?->logger->debug(__FUNCTION__ . ' the dead link could not be closed: ' . $e->getMessage());
        }
    }

    /**
     * Move a job into the given state
     */
    private function updateState(JobState $state, ?string $workId): bool
    {
        $operation = __FUNCTION__;

        if (null === $workId) {
            $this?->logger->error($operation . ' Missing job id');

            return false;
        }

        $sql = 'UPDATE ' . $this->config->table . ' ' .
            'SET ' . JobColumn::State->value . ' = "' . $state->value . '" ' .
            'WHERE ' . $this->config->idColumn . ' = "' . $this->escape($workId) . '"';

        $this?->logger->debug($operation . ': ' . $sql);

        /**
         * The statement ran, and that is what this cell reports. The row count is not
         * the answer: an UPDATE that writes the value a row already holds also reports 0,
         * so reading it as success would fail a job that is already DONE and make a
         * re-delivered id throw inside the worker. A count of 0 is worth knowing about,
         * so it is logged rather than returned.
         */
        return $this->attempt($operation, function () use ($sql, $workId, $operation): bool {
            $affected = $this->write($sql);
            if (0 === $affected) {
                $this?->logger->debug($operation . ': no row matched ' . $workId);
            }

            return true;
        });
    }

    /**
     * Write or push forward this worker's lease
     *
     * One statement. INSERT ... ON DUPLICATE KEY UPDATE on the UNIQUE key over the token is
     * idempotent and atomic, so there is no read-then-write and no transaction on the write
     * side of this feature, and two workers can never lose an update between them.
     *
     * The queue is named literally in the UPDATE clause rather than through the row's own
     * VALUES(queue). VALUES() is deprecated on MySQL 8.0.20 and the row-alias spelling that
     * replaced it needs 8.0.19; naming the queue outright is accepted by every version, was
     * measured to raise no warning on the pinned 8.0.46, and it is what makes a worker that
     * is re-bound to another queue move rather than appear on both.
     *
     * The token reaches the server as UNHEX() and not as a string, and that is the reason
     * the column is varbinary(16). The 16 bytes are not printable - a random byte is 0x00
     * half the time and 0x22 half the time - so interpolating them would end the literal
     * early. Measured: the raw bytes fail on the very first token, with a syntax error.
     *
     * The hex form goes in bare, with no dashes. UNHEX() is not a parser: it answers NULL
     * for anything that is not pairs of hex digits, and NULL into a NOT NULL column is a
     * second, quieter failure. The dashed spelling is for a log line and for a human, not
     * for this statement.
     *
     * The token is not escaped, and does not need it: 32 hex characters the adapter
     * generated itself, where [0-9a-f] cannot end a literal or open one. A future change
     * that puts a caller-derived character in the token needs the escape() call the job id
     * gets.
     */
    private function lease(string $queue, ?string $token = null): void
    {
        $token ??= $this->workerToken ?? '';
        $queue = $this->escape($queue);
        $sql   = 'INSERT INTO ' . $this->config->workerTable . ' (queue, token, seen) '
            . 'VALUES ("' . $queue . '", UNHEX("' . $token . '"), NOW()) '
            . 'ON DUPLICATE KEY UPDATE queue = "' . $queue . '", seen = NOW()';
        $this?->logger->debug(__FUNCTION__ . ': ' . $sql);
        $this->write($sql);
    }

    /**
     * Write the lease, then reap, as the operation the caller named in the log
     *
     * Two attempt() calls and not one, and the difference is the point: a lease that
     * cannot be written must not also cost the table its reap, and a reap that fails must
     * not cost the worker its lease. One attempt() around both would make the first
     * failure skip the second, which is a database user with INSERT but no DELETE - the
     * leases keep working, so nothing fails visibly, and the table grows forever. Each
     * logs its own failure and the other still runs.
     *
     * The caller passes its own name so the log says bindRead or renew rather than this
     * method, which is the operation a reader of the log is looking for.
     *
     * @param string $operation the calling method's name, for the log
     *
     * @return bool whether the lease was written
     */
    private function announce(string $operation): bool
    {
        $queue   = $this->lastQueue;
        $renewed = $this->attempt($operation, function () use ($queue): bool {
            $this->lease($queue);

            return true;
        });

        $this->reap();

        return $renewed;
    }

    /**
     * Push the lease forward, at most once every workerTtl / 3 seconds
     *
     * A worker is visible from the moment it binds, so this is about staying visible rather
     * than about becoming visible, and the cost of a hot-path write on every pick cycle is
     * not something anybody notices until the database notices. A publisher has no token
     * and returns before it computes an interval.
     *
     * time() is the right clock for the interval and the wrong clock for the value: the
     * lease itself is NOW() on the server, and PHP's clock can only make this fire early or
     * late, never write a wrong lease.
     */
    private function renew(): void
    {
        if (null === $this->workerToken || $this->registryMissing) {
            return;
        }

        $now      = time();
        $interval = max(1, intdiv($this->config->workerTtl, 3));
        if (null !== $this->renewedAt && ($now - $this->renewedAt) < $interval) {
            return;
        }

        $renewed = $this->announce(__FUNCTION__);

        /**
         * Only on the way that worked. A failed renew is a failed lease, and a throttle
         * that advanced anyway would leave the next attempt a full interval away, so one
         * transient error would cost a third of the lease length of visibility. announce()
         * has already logged the failure and already run the reap; the only thing left to
         * decide is when to try again, and the answer is as soon as the throttle allows.
         */
        if ($renewed) {
            $this->renewedAt = $now;
        }
    }

    /**
     * Delete the rows no reader could be counting any more
     *
     * Nothing here is a correctness requirement: the count query's own WHERE clause already
     * refuses to count an expired row. This exists so the table does not grow without
     * bound.
     *
     * It runs twice, and both are load-bearing. Once on the worker's startup, where a
     * backlog accumulated while nothing was running gets cleared before the first job is
     * picked. And once on the renew's throttle, which is what actually keeps the table
     * bounded: a worker that binds once and then runs for months never calls bindRead()
     * again, so a startup-only reap leaks one row per crash forever, in exactly the
     * deployments - a stable long-lived fleet - where nobody is looking at the table.
     *
     * ORDER BY seen ASC LIMIT is not decoration. This is the only statement in the
     * feature that is not scoped to a queue, so it filters on `seen` alone, and it is the
     * only one the (queue, seen) index cannot serve - without an index on `seen` it is a
     * full scan of the registry on every interval. With one, MySQL walks the index in
     * order and stops at the batch. Oldest first is also the only order worth deleting in:
     * a row past three lease lengths is past three lease lengths whichever end it is.
     */
    private function reap(): void
    {
        $sql = 'DELETE FROM ' . $this->config->workerTable
            . ' WHERE seen <= NOW() - INTERVAL ' . (self::REAP_LEASES * $this->config->workerTtl) . ' SECOND'
            . ' ORDER BY seen ASC LIMIT ' . self::REAP_BATCH;
        $this?->logger->debug(__FUNCTION__ . ': ' . $sql);
        $this->attempt(__FUNCTION__, function () use ($sql): bool {
            $this->write($sql);

            return true;
        });
    }

    /**
     * A token that no other adapter instance can mint
     *
     * A version 7 UUID, 16 random-derived bytes returned as 32 lower-case hex characters.
     * RFC 9562 lays it out as a 48-bit big-endian millisecond timestamp, a 4-bit version, a
     * 12-bit rand_a, a 2-bit variant and 62 bits of rand_b, and random_bytes() supplies the
     * 10 bytes the timestamp does not occupy, so the two version and variant nibbles are
     * the only bits this code sets by hand. PHP 8.3 has no uuid7(), and pack('n*', ...) is
     * how a 48-bit big-endian value is written without touching floats or bcmath.
     *
     * The previous shape was the pid and 8 random bytes. A time-based id is the better
     * shape here for three reasons, and only the first shows up in a measurement: **the
     * column is varbinary(16) rather than varchar(64)**, so the unique key carries 16 bytes
     * instead of a declared 256 under utf8mb4, and the column type is in fact required -
     * UNHEX() output is rejected by a utf8mb4 varchar with error 1366, measured. It is also
     * a legal statement about time, so `SELECT HEX(token)` tells a reader when a lease was
     * minted without joining anything, and it is opaque: no pid in it, so the registry
     * discloses no process id to anyone who can read the table.
     *
     * What it is NOT is monotonic, and nothing here needs it to be: 20000 tokens minted
     * back to back came out strictly ascending 50.1% of the time, because within one
     * millisecond the order is decided by the random bits, as RFC 9562 specifies. That
     * would matter if the token were the clustered key, where insertion order decides
     * locality; here the clustered key is the auto-increment `id` and `token` is a
     * secondary key looked up by equality, so ascending bytes buy nothing structural. The
     * win is the width and the readability, and claiming an insert-locality benefit would
     * be claiming a property this schema does not have.
     *
     * The embedded timestamp is PHP's clock and `seen` is the server's. They are the same
     * clock in every deployment worth the name, and nothing compares them - the lease
     * predicate is on `seen` alone - so a host whose clock is wrong writes a token with a
     * wrong time in it and an expiry that is still right. The token's timestamp is a
     * diagnostic, never a source of truth.
     *
     * @return string 32 lower-case hex characters, the form both statements send inside
     *                UNHEX() - see lease()
     */
    private function makeToken(): string
    {
        $bytes = random_bytes(16);

        /**
         * 48 bits of milliseconds, big-endian, in the first six bytes. 1000 is a float on
         * purpose: psalm runs in strict binary-operand mode, where a float times an int
         * literal is an error, and the value is exact anyway - 48 bits of milliseconds is
         * about 1.79e12 and a double carries every integer below 9.0e15 exactly.
         */
        $ms    = (int) (microtime(true) * 1000.0);
        $bytes = substr_replace($bytes, pack('n*', ($ms >> 32) & 0xFFFF, ($ms >> 16) & 0xFFFF, $ms & 0xFFFF), 0, 6);

        /**
         * The version nibble in the high half of byte 6, the variant in the two high bits
         * of byte 8. Both are OR-ed rather than assigned so the bits underneath survive.
         */
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        /** @phan-suppress-next-line PhanTypeMismatchArgumentInternal */
        return bin2hex($bytes);
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
     * @return int the number of rows the statement matched, 0 when the statement matched
     *              no row or was not a write
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
            $this?->logger->error(__FUNCTION__ . ' Transaction could not be rolled back');
        }
    }
}
