<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\MySql;

/**
 * Where the adapter keeps the jobs and how hard it pushes the database
 *
 * The columns and the table name every statement is built from, the sleeps
 * throttle the statements that would otherwise run in a tight loop
 */
final readonly class JobConfig
{
    /**
     * @param string $idColumn column holding the job id
     * @param string $dataColumn column holding the job payload
     * @param string $table table holding the jobs
     * @param int $pickMissSleep microseconds slept after a pick found no job
     * @param int $pickSuccessSleep microseconds slept after a pick took a job
     * @param int $putTaskSleep microseconds slept before a put, to avoid DB CPU usage
     */
    public function __construct(
        public string $idColumn = 'id',
        public string $dataColumn = 'payload',
        public string $table = 'backq_jobs',
        public int $pickMissSleep = 5000000,
        public int $pickSuccessSleep = 1000000,
        public int $putTaskSleep = 50000,
    ) {
    }
}
