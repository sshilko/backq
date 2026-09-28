<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\MySql;

use Closure;
use InvalidArgumentException;

/**
 * Where the adapter keeps the jobs and how hard it pushes the database
 *
 * The columns and the table name every statement is built from, the sleeps
 * throttle the statements that would otherwise run in a tight loop, and the two
 * settings that decide what hasWorkers() reads
 */
final readonly class JobConfig
{
    /**
     * The shortest lease hasWorkers() will accept, in seconds
     *
     * A lease of 0 expires the row the instant the server writes it, so hasWorkers()
     * answers false for the lifetime of every process and nothing anywhere reports an
     * error. The same floor, and for the same reason, as RedisConfig::WORKER_TTL_MIN.
     */
    public const int WORKER_TTL_MIN = 5;

    /**
     * @param string $idColumn column holding the job id
     * @param string $dataColumn column holding the job payload
     * @param string $table table holding the jobs
     * @param int $pickMissSleep microseconds slept after a pick found no job
     * @param int $pickSuccessSleep microseconds slept after a pick took a job
     * @param int $putTaskSleep microseconds slept before a put, to avoid DB CPU usage
     * @param \Closure():\mysqli|null $connectionProvider builds the link that replaces a dead
     *        one. The mysqli driver cannot reconnect, so a worker whose link drops is dead
     *        until somebody builds a new link; this is that somebody. It is called only after
     *        ping() reported the link dead, and the link it returns takes the dead one's place.
     *        Left null, the link is the caller's to replace and the adapter never closes it.
     * @param string $workerTable table holding one row per bound worker. Interpolated into
     *        SQL exactly as $table is, so it is configuration and not input. The user
     *        creates it, and an adapter pointed at a table that does not exist answers
     *        "no workers" rather than raising. The DDL is in the README.
     * @param int $workerTtl seconds a worker's lease stays valid without a renew. Typed int
     *        because it is interpolated as `INTERVAL <ttl> SECOND` and this adapter has no
     *        prepared statements, so the same field changed to string is a query injection
     *        and no static analyser here would catch it. It must exceed the longest job a
     *        worker runs: a worker that is mid-job is not renewing, and a job longer than the
     *        lease is a job during which the worker is invisible.
     */
    public function __construct(
        public string $idColumn = 'id',
        public string $dataColumn = 'payload',
        public string $table = 'backq_jobs',
        public int $pickMissSleep = 5000000,
        public int $pickSuccessSleep = 1000000,
        public int $putTaskSleep = 50000,
        public ?Closure $connectionProvider = null,
        public string $workerTable = 'backq_workers',
        public int $workerTtl = 300,
    ) {
        if ($workerTtl < self::WORKER_TTL_MIN) {
            throw new InvalidArgumentException(
                'workerTtl must be at least ' . self::WORKER_TTL_MIN . ' seconds, got ' . $workerTtl
            );
        }
    }
}
