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
 * The states a job row can have in the MySQL queue table.
 *
 * Backed by the `sync` column values declared by the table schema:
 * WAIT->LOCK->[DONE | HOLD]
 */
enum JobState: string
{
    case Wait = 'WAIT';
    case Lock = 'LOCK';
    case Done = 'DONE';
    case Hold = 'HOLD';
}
