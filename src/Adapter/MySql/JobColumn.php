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
 * The job table columns the adapter owns, the id and the payload columns are
 * named by the constructor instead.
 *
 * Backed by the column names of the table schema.
 */
enum JobColumn: string
{
    /**
     * Holds the `JobState` of the job
     */
    case State = 'sync';

    /**
     * Holds the time the job last changed its state
     */
    case Time = 'time_sync';
}
