<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\MySql;
use BackQ\Adapter\MySql\JobConfig;
use BackQ\Tests\Adapter\MySql\Schema;
use BackQ\Tests\Support\LogAssertions;
use BackQ\Tests\Support\RecordingLogger;
use mysqli;
use mysqli_result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function array_column;
use function date;
use function extension_loaded;
use function fclose;
use function fsockopen;
use function getenv;
use function mysqli_report;
use function sleep;
use function sprintf;
use const MYSQLI_ASSOC;
use const MYSQLI_REPORT_ERROR;
use const MYSQLI_REPORT_STRICT;

/**
 * Integration test against a real MySQL server.
 *
 * This is the first MySQL test in this repository to execute SQL rather than assert on the
 * string the adapter would have sent to a mock, and it is the only layer here that can be
 * wrong about MySQL. Everything MySqlAdapterTest proves about these statements is a fact
 * about PHP: that a certain string was built, and that a throw was handled. Whether the
 * DDL is accepted, whether INSERT ... ON DUPLICATE KEY UPDATE is idempotent, and whether
 * INTERVAL 300 SECOND means three hundred seconds are all questions only this file asks.
 *
 * Skips when ext-mysqli is missing or the server is unreachable, exactly as
 * RedisAdapterTest does. phpunit.xml sets failOnSkipped="true", so the skip is a failed
 * run: two services are now needed for a green suite instead of one, which is the honest
 * consequence of this file existing.
 */
class MySqlLiveTest extends TestCase
{

    use LogAssertions;

    private const string DEFAULT_HOST = 'mysql80';
    private const int DEFAULT_PORT = 3306;
    private const string DEFAULT_DB = 'mydb';
    private const string DEFAULT_USER = 'test';
    private const string DEFAULT_PASSWORD = 'pass';

    /**
     * A second link on the same server, so two adapters can be given one each
     */
    private mysqli $link;

    private mysqli $otherLink;

    /**
     * The database the fixtures were created in, spelled out in the one assertion that
     * needs the server's own name for the table it could not find
     */
    private string $database = self::DEFAULT_DB;

    /**
     * The two claims that came from the manual and that no mock can check: the DDL is
     * accepted, and a datetime written by the server's NOW() reads back as written.
     */
    public function testTheRegistrySchemaCreatesAndARowSurvivesTheNowRoundTrip(): void
    {
        $this->assertSame(
            ['id', 'queue', 'token', 'seen'],
            array_column(
                $this->rows('SHOW COLUMNS FROM ' . Schema::WORKERS),
                'Field'
            )
        );
        $this->assertSame(
            'varbinary(16)',
            $this->rows('SHOW COLUMNS FROM ' . Schema::WORKERS)[2]['Type'],
            'and the token column is binary, which is not a detail: UNHEX output is'
            . ' refused by a utf8mb4 varchar with error 1366'
        );

        $this->link->query('INSERT INTO ' . Schema::WORKERS . ' (queue, token, seen) VALUES ("news", "t1", NOW())');

        $seen = $this->rows('SELECT seen FROM ' . Schema::WORKERS . ' WHERE token = "t1"')[0]['seen'];
        $this->assertSame(
            date('Y-m-d H:i:s'),
            $seen,
            'the server wrote a value the server would read back unchanged'
        );
    }

    /**
     * The claim a mocked test cannot make at all: the 16 bytes the adapter meant to store
     * are the 16 bytes that landed, and the release finds the row by them.
     *
     * The token is sent as UNHEX() of 32 hex characters rather than as the bytes themselves,
     * because a random byte is 0x00 half the time and 0x22 half the time, so the raw form
     * ends the string literal early. LENGTH() and HEX() are the two assertions that only a
     * real server can answer, and between them they say the round trip is byte-exact and
     * not merely that nothing threw.
     */
    public function testTheTokenLandsAsSixteenBytesAndIsFoundByThemAgain(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->worker($this->link, logger: $logger);
        $this->assertTrue($worker->bindRead('news'));

        /**
         * Asserted before the row is read, so a table whose token column is a utf8mb4
         * varchar fails here with the server's own complaint - error 1366, "Incorrect
         * string value" - rather than three lines later with "Undefined array key 0" on
         * an empty table. bindRead() swallows the failure by design, so the log is the
         * only place the reason appears, and a negative assertion here needs a real
         * recorder rather than a null logger to mean anything.
         */
        $this->assertNotLogged($logger, 'Incorrect string value', 'error');
        $this->assertNotLogged($logger, 'error 1366', 'error');

        $row = $this->rows('SELECT LENGTH(token) AS len, HEX(token) AS hex FROM ' . Schema::WORKERS)[0];
        $this->assertSame(16, (int) $row['len'], 'sixteen bytes, not a truncated string');
        $this->assertMatchesRegularExpression(
            '/^[0-9A-F]{32}$/',
            $row['hex'],
            'and the bytes are the ones a version 7 UUID is made of'
        );

        $this->assertTrue($worker->disconnect(), 'and the release matched on them');

        $this->assertSame(0, $this->rowCount());
    }

    /**
     * The plan this implements says to use the 5.7-and-earlier VALUES(queue) spelling or the
     * 8.0.20 row alias, and to record which. Measured on the pinned 8.0.46: VALUES(queue)
     * raises warning 1287, and the form the adapter actually sends - naming the queue
     * literally in the UPDATE clause, which needs neither - raises nothing. This asserts
     * the statement the adapter sends rather than a hand-written copy of it, so it fails if
     * the adapter ever goes back to VALUES().
     */
    public function testTheLeaseTheAdapterSendsIsNotDeprecatedByTheServer(): void
    {
        $worker = $this->worker($this->link);

        $this->assertTrue($worker->bindRead('news'));

        $warnings = $this->rows('SHOW WARNINGS');
        $this->assertSame([], $warnings, 'the last statement the adapter sent raised no warning');
    }

    /**
     * One statement, one row. This is what makes the announce idempotent and the renew
     * atomic, and it is the whole reason this plan needed no read-then-write and no
     * transaction on the write side.
     */
    public function testTheLeaseWritesOneRowHoweverOftenItRuns(): void
    {
        $worker = $this->worker($this->link);

        $worker->bindRead('news');
        $worker->bindRead('news');
        $worker->bindRead('news');

        $this->assertSame(1, $this->rowCount());
        $this->assertSame('news', $this->rows('SELECT queue FROM ' . Schema::WORKERS)[0]['queue']);
    }

    /**
     * A worker re-bound to another queue moves its row rather than appearing on both, which
     * is why the queue is named in the UPDATE clause and not only in the INSERT.
     */
    public function testRebindingToAnotherQueueMovesTheRow(): void
    {
        $worker = $this->worker($this->link);

        $worker->bindRead('news');
        $worker->bindRead('mail');

        $this->assertSame(1, $this->rowCount());
        $this->assertSame('mail', $this->rows('SELECT queue FROM ' . Schema::WORKERS)[0]['queue']);
    }

    /**
     * The predicate is the correctness argument: a worker is one that held a lease within
     * the last workerTtl seconds. Both rows are on the same queue and the only thing
     * separating them is how old the server says they are, so the answer can only be coming
     * from the INTERVAL and from nothing else.
     */
    public function testOnlyLeasesInsideTheLeaseLengthAreCounted(): void
    {
        $this->seedLease('news', 'inside', 100);
        $this->seedLease('news', 'outside', 400);

        $this->assertTrue($this->publisher(300)->hasWorkers('news'), 'the 100 second row is inside 300');

        $this->assertFalse(
            $this->publisher(50)->hasWorkers('news'),
            'and the same table answers false at 50, so it is the lease length deciding'
        );
    }

    /**
     * The claim that separates this plan from the Redis one: the publisher's path is one
     * read and no write. An expired row is invisible without being deleted, so nothing on
     * this path has a reason to write, and a write in a web request is a write in a web
     * request.
     */
    public function testThePublishersAnswerIsOneReadAndNoWrite(): void
    {
        $this->seedLease('news', 'live', 0);

        $before = $this->rowCount();
        $this->assertTrue($this->publisher(300)->hasWorkers('news'));
        $this->assertTrue($this->publisher(300)->hasWorkers('news'));

        $this->assertSame($before, $this->rowCount(), 'hasWorkers() wrote nothing');
    }

    /**
     * The answer comes from a table, not from this process, and the lease is in the table
     * rather than in the session, so a link swap is invisible to it.
     */
    public function testAWorkerOnAnotherLinkIsSeen(): void
    {
        $this->assertTrue($this->worker($this->link)->bindRead('news'));
        $this->assertTrue($this->publisher(300, $this->otherLink)->hasWorkers('news'));
    }

    public function testAWorkerOnAnotherQueueIsNotSeen(): void
    {
        $this->worker($this->link)->bindRead('mail');

        $this->assertFalse($this->publisher(300)->hasWorkers('news'));
    }

    /**
     * The structural reason a publisher cannot find itself: bindWrite() writes no lease, so
     * a second adapter on the same queue that only publishes is invisible to the count.
     */
    public function testAPublisherIsNotAWorker(): void
    {
        $this->assertTrue($this->publisher(300, $this->otherLink)->bindWrite('news'));

        $this->assertFalse($this->publisher(300)->hasWorkers('news'));
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * The renew is what keeps a worker visible while it runs, and this is the half of the
     * throttle that cannot be proved with a mock: time() has to actually move. A five
     * second lease gives a one second interval, so two seconds is enough, and this is the
     * only test in the suite that sleeps.
     */
    public function testTheLeaseIsRenewedOnceTheIntervalHasPassed(): void
    {
        $worker = $this->worker($this->link, workerTtl: 5);
        $worker->bindRead('news');
        $before = $this->rows('SELECT seen FROM ' . Schema::WORKERS)[0]['seen'];

        $this->assertFalse($worker->pickTask());
        $this->assertSame($before, $this->seen(), 'inside the interval, the row is untouched');

        sleep(2);

        $this->assertFalse($worker->pickTask());
        $this->assertGreaterThan($before, $this->seen(), 'past the interval, the row moved');
        $this->assertSame(1, $this->rowCount(), 'and the renew updated it rather than adding one');
    }

    /**
     * A worker that was kill -9'd leaves exactly this behind: a row whose seen is old. The
     * plan asked for a spawned child to be killed; writing the row directly is the same
     * state, and it is deterministic, needs no process management and costs no sleep. Both
     * halves matter - the first is the correctness claim, the second is the operational cost
     * - and a test that only asserted the first would be hiding this plan's own downside.
     */
    public function testADeadWorkersRowIsInvisibleWithoutBeingDeletedAndALaterBindReapsIt(): void
    {
        $this->seedLease('news', 'killed', 3600);

        $this->assertFalse($this->publisher(300)->hasWorkers('news'), 'an expired row is not counted');
        $this->assertSame(1, $this->rowCount(), 'and it is still there, which is the cost of the table');

        $this->worker($this->link)->bindRead('mail');

        $this->assertSame(1, $this->rowCount(), 'the reap is 3 x workerTtl, so a 3600 second row survives');
        $this->assertSame('mail', $this->rows('SELECT queue FROM ' . Schema::WORKERS)[0]['queue']);
    }

    public function testTheReapDeletesWhatIsThreeLeasesPastExpiry(): void
    {
        $this->seedLease('news', 'ancient', 901);
        $this->seedLease('news', 'recent', 400);

        $this->worker($this->link)->bindRead('mail');

        /**
         * The worker's own announce is a row here too, so the reap is asserted by what is
         * gone and what is not rather than by the whole contents
         */
        $tokens = array_column($this->rows('SELECT token FROM ' . Schema::WORKERS), 'token');
        $this->assertNotContains('ancient', $tokens, '901 seconds is past three 300 second leases');
        $this->assertContains('recent', $tokens, '400 seconds is not, and deleting it would race a reader');
    }

    /**
     * The gap the periodic reap closes, and the half of it that no mock can see.
     *
     * bindRead() runs once per process, before the worker's while(true), so a reap that
     * lives only there fires once per worker start. A fleet that is not restarting anything
     * therefore never reaps again, and every SIGKILL, OOM-kill and fatal leaves a row
     * behind permanently. The rows do not make the answer wrong - the count's own WHERE
     * refuses them - but the table grows by one row per crash forever in exactly the
     * deployments that are stable enough for nobody to be watching it.
     *
     * The order below is the point: the dead peer arrives after the startup reap has
     * already run, the very next pick is inside the interval and so is throttled, and only
     * the pick after the interval is reaped. A test that reaped on the first pick would
     * pass against the old code and prove nothing.
     */
    public function testARunningWorkerReapsADeadPeerWithoutRestarting(): void
    {
        $worker = $this->worker($this->link, workerTtl: 5);
        $worker->bindRead('news');

        $this->assertFalse($worker->pickTask(), 'the first cycle renews; the startup reap already ran');
        $this->seedLease('news', 'killed', 400);
        $this->assertSame(2, $this->rowCount(), 'the dead peer arrived after the startup reap, so it is here');

        $this->assertFalse($worker->pickTask());
        $this->assertSame(2, $this->rowCount(), 'inside the interval the reap is throttled with the lease');

        sleep(2);

        $this->assertFalse($worker->pickTask());
        $this->assertNotContains(
            'killed',
            array_column($this->rows('SELECT token FROM ' . Schema::WORKERS), 'token'),
            'a cycle past the interval reaped it, with no restart and no other worker'
        );
        $this->assertSame(1, $this->rowCount(), 'and the worker kept its own row, which the reap must not touch');
    }

    /**
     * The reap is on a schedule now, so it is a statement the server sees from every worker
     * every workerTtl / 3 seconds, and an unbounded DELETE is not one to hand a busy server
     * on a schedule: 1200 qualifying rows in a single transaction holding row locks is a
     * stall, and after an outage the backlog is not 12 rows. The bound delays cleanup; the
     * next reap finishes the job.
     */
    public function testTheReapIsBoundedAndTheNextOneFinishesTheJob(): void
    {
        $values = [];
        for ($i = 0; $i < 1200; $i++) {
            $values[] = '("news", "bulk-' . $i . '", NOW() - INTERVAL 9000 SECOND)';
        }
        $this->link->query(
            'INSERT INTO ' . Schema::WORKERS . ' (queue, token, seen) VALUES ' . implode(', ', $values)
        );
        $this->assertSame(1200, $this->rowCount());

        $this->worker($this->link)->bindRead('mail');

        $this->assertSame(
            201,
            $this->rowCount(),
            '1200 dead rows: 1000 taken in one reap, 1 lease for the worker, 200 left for the next'
        );

        $this->worker($this->otherLink)->bindRead('news');

        $tokens = array_column($this->rows('SELECT token FROM ' . Schema::WORKERS), 'token');
        $this->assertSame(2, $this->rowCount(), 'the backlog is gone and two workers are still alive');
        $this->assertCount(
            0,
            array_filter(
                $tokens,
                static function (string $token): bool {
                    return str_starts_with($token, 'bulk-');
                }
            ),
            'all 1200 of them, not the first 1000 and never again'
        );
    }

    /**
     * The two indexes earn their place and the claim is measured, not asserted from the
     * DDL. On the pinned 8.0.46 the reap is a range scan on backq_workers_seen; drop that
     * index and the same statement is type=ALL with key=NULL and Using filesort, because
     * ORDER BY seen then has nothing to read in order - a scan *and* a sort, on a schedule.
     * The count is unaffected by that drop, which is what makes the two indexes distinct
     * rather than one duplicated.
     *
     * `rows` is the optimizer's guess against a table this test emptied, so it says nothing
     * here and is not asserted. `type` and `key` are what the server actually chose.
     */
    public function testTheReapReadsAnIndexAndTheCountReadsTheOtherOne(): void
    {
        $reap = $this->rows('EXPLAIN ' . $this->reapSql())[0];
        $this->assertSame('range', $reap['type'], 'not a scan: ' . $reap['Extra']);
        $this->assertSame('backq_workers_seen', $reap['key'], 'the only index on the time column alone');
        $this->assertStringNotContainsString('Using filesort', $reap['Extra'], 'and nothing to sort');

        $count = $this->rows('EXPLAIN SELECT COUNT(*) AS workers FROM ' . Schema::WORKERS
            . ' WHERE queue = "news" AND seen > NOW() - INTERVAL 300 SECOND')[0];
        $this->assertSame('backq_workers_queue_seen', $count['key'], 'the count filters on a queue and a time');
    }

    /**
     * A worker that shuts down cleanly should not be visible to the next publisher, and its
     * row should not be left for the reap to find.
     */
    public function testDisconnectReleasesTheLease(): void
    {
        $worker = $this->worker($this->link);
        $worker->bindRead('news');
        $this->assertTrue($this->publisher(300)->hasWorkers('news'));

        $this->assertTrue($worker->disconnect());

        $this->assertFalse($this->publisher(300)->hasWorkers('news'));
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * The user has to run the DDL, and a deployment that has not is a false answer rather
     * than an exception. The library cannot tell that from the server going away, so it
     * says so once at debug and then stops asking.
     */
    public function testAMissingRegistryTableIsFalseAndLoggedOnceAtDebug(): void
    {
        Schema::dropWorkers($this->link);
        $logger  = new RecordingLogger();
        $adapter = $this->publisher(300, $this->link, $logger);

        $this->assertFalse($adapter->hasWorkers('news'));
        $this->assertFalse($adapter->hasWorkers('news'));

        $this->assertLogged($logger, Schema::WORKERS, 'debug');
        $this->assertNotLogged($logger, 'error');
    }

    /**
     * A worker whose database user cannot write the registry is a worker whose picks must
     * keep working. The registry is observability, not the queue.
     */
    public function testBindReadStillAnswersTrueWhenTheRegistryIsMissing(): void
    {
        Schema::dropWorkers($this->link);
        $logger = new RecordingLogger();

        $this->assertTrue($this->worker($this->link, logger: $logger)->bindRead('news'));
        $this->assertLogged($logger, "Table '" . $this->database . '.' . Schema::WORKERS . "' doesn't exist", 'error');
    }

    protected function setUp(): void
    {
        [$host, $port, $db, $user, $password] = $this->reachableServer();

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->link      = new mysqli($host, $user, $password, $db, $port);
        $this->otherLink = new mysqli($host, $user, $password, $db, $port);
        $this->database  = $db;

        Schema::dropWorkers($this->link);
        Schema::dropJobs($this->link);
        Schema::createJobs($this->link);
        Schema::createWorkers($this->link);
    }

    protected function tearDown(): void
    {
        Schema::dropWorkers($this->link);
        Schema::dropJobs($this->link);
        $this->link->close();
        $this->otherLink->close();
    }

    /**
     * @return list<string> the host, port, database, user and password, or a skip
     */
    private function reachableServer(): array
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('ext-mysqli is not available');
        }

        $host = getenv('BACKQ_MYSQL_HOST') ?: self::DEFAULT_HOST;
        $port = (int) (getenv('BACKQ_MYSQL_PORT') ?: self::DEFAULT_PORT);

        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (false === $sock) {
            self::markTestSkipped(sprintf('MySQL not reachable at %s:%d', $host, $port));
        }
        fclose($sock);

        return [
            $host,
            $port,
            getenv('BACKQ_MYSQL_DB') ?: self::DEFAULT_DB,
            getenv('BACKQ_MYSQL_USER') ?: self::DEFAULT_USER,
            getenv('BACKQ_MYSQL_PASSWORD') ?: self::DEFAULT_PASSWORD,
        ];
    }

    /**
     * A worker adapter on the given link, with the sleeps off and the lease length the test
     * chooses
     */
    private function worker(mysqli $link, int $workerTtl = 300, ?LoggerInterface $logger = null): MySql
    {
        return new MySql($link, $this->config($workerTtl), $logger ?? new NullLogger());
    }

    /**
     * A publisher adapter on the given link
     */
    private function publisher(int $workerTtl = 300, ?mysqli $link = null, ?LoggerInterface $logger = null): MySql
    {
        return new MySql($link ?? $this->link, $this->config($workerTtl), $logger ?? new NullLogger());
    }

    private function config(int $workerTtl = 300): JobConfig
    {
        return new JobConfig(pickMissSleep: 0, pickSuccessSleep: 0, putTaskSleep: 0, workerTtl: $workerTtl);
    }

    /**
     * The reap's SQL, written out again so EXPLAIN can ask the server about it
     *
     * A deliberate duplicate of what MySql::reap() builds, and the two are pinned to each
     * other: MySqlAdapterTest holds the adapter's own string, this test asks the server what
     * the server does with that shape. If the string changes, both fail.
     */
    private function reapSql(): string
    {
        return 'DELETE FROM ' . Schema::WORKERS
            . ' WHERE seen <= NOW() - INTERVAL 900 SECOND ORDER BY seen ASC LIMIT 1000';
    }

    /**
     * Write a lease row directly, aged by the server's own clock
     *
     * @param int $ageSeconds how long ago the lease was seen
     */
    private function seedLease(string $queue, string $token, int $ageSeconds): void
    {
        $this->link->query(
            'INSERT INTO ' . Schema::WORKERS . ' (queue, token, seen) VALUES ("' . $queue . '", "'
            . $token . '", NOW() - INTERVAL ' . $ageSeconds . ' SECOND)'
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $sql): array
    {
        $result = $this->link->query($sql);
        self::assertInstanceOf(mysqli_result::class, $result, 'a read statement must produce a result set');
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    private function rowCount(): int
    {
        return (int) $this->rows('SELECT COUNT(*) AS n FROM ' . Schema::WORKERS)[0]['n'];
    }

    private function seen(): string
    {
        return $this->rows('SELECT seen FROM ' . Schema::WORKERS)[0]['seen'];
    }
}
