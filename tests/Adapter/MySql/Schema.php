<?php

namespace BackQ\Tests\Adapter\MySql;

use mysqli;

/**
 * The DDL the MySQL adapter needs, kept as a fixture so the live tests can create it
 * and drop it again.
 *
 * Until the live tests existed this repository had never run a MySQL statement, and
 * the job table the adapter has always assumed was only ever described in its
 * docblock. The DDL is written here rather than shipped in `src/` because the adapter
 * does not create tables: `hasWorkers()` reads a registry the user is expected to
 * create, and a deployment that has not degrades to "no workers" rather than raising.
 */
final class Schema
{

    /**
     * The queue table, as the adapter's statements assume it
     */
    public const string JOBS = 'backq_jobs';

    /**
     * The worker registry `hasWorkers()` reads
     *
     * Spelled out here rather than read from the config: the point of the assertion is
     * that the table the adapter writes to is the table this expects, so a change to
     * the default has to fail a test rather than be followed by one.
     */
    public const string WORKERS = 'backq_workers';

    public static function createJobs(mysqli $link): void
    {
        $link->query(
            'CREATE TABLE IF NOT EXISTS `' . self::JOBS . '` ('
                . '`id` varchar(64) NOT NULL,'
                . '`payload` text NOT NULL,'
                . '`sync` varchar(16) NOT NULL,'
                . '`time_sync` datetime NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'KEY `backq_jobs_state` (`sync`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * The registry of 11.1, with its three load-bearing choices
     *
     * `datetime` and not `timestamp`: on the pre-8.0.2 default of
     * `explicit_defaults_for_timestamp=OFF` the first TIMESTAMP column silently takes
     * `ON UPDATE CURRENT_TIMESTAMP`, which would rewrite `seen` on any update at all.
     * `varchar(64)` and not 255: the queue column is indexed, and 191 * 4 bytes is
     * under the 767-byte InnoDB index prefix that predates `innodb_large_prefix`.
     * And the UNIQUE key is on `token` alone, because that is what
     * `INSERT ... ON DUPLICATE KEY UPDATE` keys on and what makes one statement
     * idempotent.
     *
     * Both secondary indexes earn their place and neither is a copy of the other. The
     * count in hasWorkers() filters on a queue *and* a time, so it needs (queue, seen).
     * The reap filters on the time alone - it is the one statement in the feature not
     * scoped to a queue - so it needs (seen), and without it that DELETE is a full scan
     * of the registry on every renew interval. Dropping either one turns a statement the
     * server does constantly into a statement it does slowly.
     *
     * `token` is `varbinary(16)` and it is not a size preference. The adapter sends the
     * token as `UNHEX()` of its 32 hex characters, and a utf8mb4 varchar refuses 16 raw
     * bytes outright - error 1366, "Incorrect string value", measured on 8.0.46 - so the
     * change to binary is what lets the statement work at all. The size follows from that:
     * 16 bytes in the unique key rather than a declared 256.
     */
    public static function createWorkers(mysqli $link): void
    {
        $link->query(
            'CREATE TABLE IF NOT EXISTS `' . self::WORKERS . '` ('
                . '`id` int unsigned NOT NULL AUTO_INCREMENT,'
                . '`queue` varchar(64) NOT NULL,'
                . '`token` varbinary(16) NOT NULL,'
                . '`seen` datetime NOT NULL,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `backq_workers_token` (`token`),'
                . 'KEY `backq_workers_queue_seen` (`queue`, `seen`),'
                . 'KEY `backq_workers_seen` (`seen`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function dropJobs(mysqli $link): void
    {
        $link->query('DROP TABLE IF EXISTS `' . self::JOBS . '`');
    }

    public static function dropWorkers(mysqli $link): void
    {
        $link->query('DROP TABLE IF EXISTS `' . self::WORKERS . '`');
    }
}
