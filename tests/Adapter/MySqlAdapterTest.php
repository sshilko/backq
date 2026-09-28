<?php

namespace BackQ\Tests\Adapter;

use ArgumentCountError;
use BackQ\Adapter\MySql;
use BackQ\Adapter\MySql\JobConfig;
use BackQ\Adapter\MySql\JobState;
use BackQ\Tests\Support\LogAssertions;
use BackQ\Tests\Support\RecordingLogger;
use Closure;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Throwable;
use TypeError;
use function array_column;
use function array_filter;
use function array_is_list;
use function array_shift;
use function array_values;
use function count;
use function get_debug_type;
use function hexdec;
use function implode;
use function is_array;
use function microtime;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function substr;
use function var_export;

class MySqlAdapterTest extends TestCase
{

    use LogAssertions;

    /**
     * The token, as a pattern, in the three places a statement names it
     *
     * A version 7 UUID is 32 hex characters, and the pattern pins the two fields that
     * make it one rather than 16 random bytes: the version nibble at offset 12 is a 7, and
     * the variant at offset 16 is 8, 9, a or b. The other 30 positions are unconstrained
     * on purpose - the timestamp and the random tail are not this layer's claim, and
     * testTheTokenEncodesTheMomentItWasMinted() is.
     */
    private const string TOKEN = '[0-9a-f]{12}7[0-9a-f]{3}[89ab][0-9a-f]{15}';

    /**
     * The statements the adapter sent, in order
     *
     * @var array<int, string>
     */
    private array $statements = [];

    /**
     * What the next statement is answered with
     *
     * @var array<int, array<mixed>|Throwable>
     */
    private array $answers = [];

    private ?Throwable $defaultFailure = null;

    /**
     * The one claim about the token that is not "it is 16 random bytes", and the reason it
     * is worth building by hand rather than calling bin2hex(random_bytes(16)).
     *
     * A UUIDv7 carries 48 bits of big-endian milliseconds in its first six bytes, so the
     * first twelve hex characters are the timestamp and the rest is the version, the
     * variant and the random tail. Decoding that here, against PHP's own clock, is what
     * makes "time-based" a checked property rather than a comment - and a wrong layout
     * would still satisfy a pattern that only looked for a 7 somewhere.
     *
     * The window is a second in each direction because the only clock involved is this
     * one, and a test that asserted the same millisecond would be asserting the scheduler.
     */
    public function testTheTokenEncodesTheMomentItWasMinted(): void
    {
        $before = (int) (microtime(true) * 1000);
        $this->assertTrue($this->adapter($this->db())->bindRead('news'));
        $after = (int) (microtime(true) * 1000);

        $token  = $this->tokenIn($this->statements[0]);
        $minted = $this->millisIn($token);

        $this->assertGreaterThanOrEqual($before - 1000, $minted, 'not before the call');
        $this->assertLessThanOrEqual($after + 1000, $minted, 'and not after it');
    }

    /**
     * The same claim from the other side: two tokens minted in separate adapters are
     * different, which is what the UNIQUE key relies on. The pid used to make that
     * obvious to a human reading the table; the timestamp alone does not, so this is the
     * test that has to stand in for that reassurance.
     */
    public function testTwoAdaptersNeverShareAToken(): void
    {
        $this->assertTrue($this->adapter($this->db())->bindRead('news'));
        $first = $this->tokenIn($this->statements[0]);

        $this->assertTrue($this->adapter($this->db())->bindRead('news'));

        $this->assertNotSame(
            $first,
            $this->tokenIn($this->statements[0]),
            'two adapters in one process, so the only thing that could differ is the token'
        );
    }

    public function testConnectOnlyPingsTheLink(): void
    {
        $db = $this->db();
        $db->method('ping')->willReturn(true);

        $this->assertTrue($this->adapter($db)->connect());
    }

    public function testConnectFailsWhenTheLinkIsGone(): void
    {
        $db = $this->db();
        $db->method('ping')->willReturn(false);

        $this->assertFalse($this->adapter($db)->connect());
    }

    public function testPingReportsADeadLinkWithoutThrowing(): void
    {
        $db = $this->db();
        $db->method('ping')->willThrowException(new mysqli_sql_exception('MySQL server has gone away'));

        $this->assertFalse($this->adapter($db)->ping());
    }

    public function testDisconnectKeepsTheCallerOwnedLinkOpen(): void
    {
        $db = $this->db();
        $db->expects($this->never())->method('close');

        $this->assertTrue($this->adapter($db)->disconnect());
    }

    /**
     * The adapter answers all eleven methods, so an `abstract` on the class is decoration
     * that makes every caller write a one-line subclass to get an instance
     */
    public function testTheAdapterItselfIsInstantiable(): void
    {
        $this->assertFalse((new ReflectionClass(MySql::class))->isAbstract());
        $this->assertInstanceOf(MySql::class, $this->adapter($this->db()));
    }

    public function testADeadLinkIsReplacedByTheLinkTheProviderBuilds(): void
    {
        $dead = $this->db();
        $dead->method('ping')->willReturn(false);
        $dead->expects($this->once())->method('close');

        $fresh = $this->db();
        $fresh->method('ping')->willReturn(true);

        $asked = 0;
        $config = $this->config(static function () use ($fresh, &$asked): mysqli {
            $asked++;

            return $fresh;
        });

        $adapter = $this->adapter($dead, $config);

        $this->assertTrue($adapter->connect());
        $this->assertSame(1, $asked);

        $this->assertTrue($adapter->afterWorkSuccess('7'));
        $this->assertSame(
            ['UPDATE backq_jobs SET sync = "DONE" WHERE id = "7"'],
            $this->statements,
            'the statements must go to the link the provider built'
        );
    }

    public function testTheProviderIsNotAskedWhileTheLinkIsAlive(): void
    {
        $db = $this->db();
        $db->method('ping')->willReturn(true);
        $db->expects($this->never())->method('close');

        $asked = 0;
        $config = $this->config(static function () use ($db, &$asked): mysqli {
            $asked++;

            return $db;
        });

        $this->assertTrue($this->adapter($db, $config)->connect());
        $this->assertSame(0, $asked);
    }

    public function testPingWithoutReconnectLeavesTheDeadLinkAlone(): void
    {
        $db = $this->db();
        $db->method('ping')->willReturn(false);
        $db->expects($this->never())->method('close');

        $asked = 0;
        $config = $this->config(static function () use ($db, &$asked): mysqli {
            $asked++;

            return $db;
        });

        $this->assertFalse($this->adapter($db, $config)->ping(reconnect: false));
        $this->assertSame(0, $asked);
    }

    public function testADeadLinkIsLeftOpenWhenNoProviderIsConfigured(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();
        $db->method('ping')->willReturn(false);
        $db->expects($this->never())->method('close');

        $this->assertFalse($this->adapter($db, null, $logger)->connect());
        $this->assertStringContainsString('connectionProvider', $this->messages($logger));
    }

    public function testAFailingProviderIsLoggedAndLeavesTheCallerItsLink(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();
        $db->method('ping')->willReturn(false);
        $db->expects($this->never())->method('close');

        $config = $this->config(static function (): mysqli {
            throw new RuntimeException('no server today');
        });

        $this->assertFalse($this->adapter($db, $config, $logger)->connect());
        $this->assertStringContainsString('no server today', $this->messages($logger));
    }

    /**
     * Closing first would hand the caller a dead link and then fail to replace it, so the
     * dead one is closed only once there is a live one to take its place
     */
    public function testTheDeadLinkIsClosedOnlyAfterTheReplacementExists(): void
    {
        $closed = false;

        $db = $this->db();
        $db->method('ping')->willReturn(false);
        $db->method('close')->willReturnCallback(static function () use (&$closed): bool {
            $closed = true;

            return true;
        });

        $fresh = $this->db();
        $fresh->method('ping')->willReturn(true);

        $config = $this->config(function () use (&$closed, $fresh): mysqli {
            $this->assertFalse($closed, 'the caller still owns its link while the provider runs');

            return $fresh;
        });

        $this->assertTrue($this->adapter($db, $config)->connect());
        $this->assertTrue($closed);
    }

    /**
     * The queue name does not reach the job table: one table holds every queue's jobs, which
     * is why the worker registry of the hasWorkers() feature needed a queue column of its
     * own. What bindRead() does with the name is write the registry, and nothing it writes
     * names backq_jobs.
     */
    public function testTheQueueNameIsIrrelevantTheTableIsTheQueue(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->bindRead('news'));
        $this->assertTrue($adapter->bindWrite('news'));
        $this->assertNotSame([], $this->statements);
        foreach ($this->statements as $sql) {
            $this->assertStringNotContainsString('backq_jobs', $sql);
        }
    }

    /**
     * The registry table and the lease length live on JobConfig, and a caller that never
     * touches them gets a table it has to create. Both are asserted literally rather than
     * read from the config, so a change to the default has to fail a test rather than be
     * followed by one.
     */
    public function testBindReadWritesALeaseForTheQueue(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->bindRead('news'));
        $this->assertMatchesRegularExpression(
            '#^INSERT INTO backq_workers \(queue, token, seen\) VALUES \("news", UNHEX\("'
            . self::TOKEN . '"\), NOW\(\)\) '
            . 'ON DUPLICATE KEY UPDATE queue = "news", seen = NOW\(\)$#',
            $this->statements[0]
        );
    }

    /**
     * The token is minted once per adapter, so a second bindRead() writes the same row
     * rather than a second one. Offline this is the whole of the claim: the table's UNIQUE
     * key is on the token, so "one row" reduces to "one token". MySqlLiveTest asserts the
     * row count against a real server.
     */
    public function testBindReadTakesTheLeaseOnlyOnce(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $adapter->bindRead('news');
        $adapter->bindRead('news');

        $leases = $this->insertsInto('backq_workers');
        $this->assertCount(2, $leases, 'both binds write, the second is an update of the same row');
        $this->assertSame($this->tokenIn($leases[0]), $this->tokenIn($leases[1]));
    }

    /**
     * The structural reason a publisher cannot find itself: nothing it does produces a lease,
     * so hasWorkers() counting leases is not a question about this adapter at all
     */
    public function testBindWriteTakesNoLease(): void
    {
        $db = $this->db();

        $this->assertTrue($this->adapter($db)->bindWrite('news'));
        $this->assertSame([], $this->statements);
    }

    public function testBindReadReapsRowsOlderThanThreeLeases(): void
    {
        $db = $this->db();

        $this->assertTrue($this->adapter($db, $this->registryConfig())->bindRead('news'));
        $this->assertSame(
            'DELETE FROM backq_workers WHERE seen <= NOW() - INTERVAL 900 SECOND ORDER BY seen ASC LIMIT 1000',
            $this->statements[1],
            'bounded and oldest-first: this now runs on a schedule, not once per deployment'
        );
    }

    /**
     * The gap this closes. bindRead() is called once per process, before the worker's
     * while(true) loop, so a startup-only reap leaks one row per crash forever in exactly
     * the deployments - a stable long-lived fleet - where nobody is restarting anything to
     * notice. renew() is inside the loop, so reaping there is what makes the table bounded.
     */
    public function testAPickCycleReapsAsWellAsRenews(): void
    {
        $db      = $this->db([[], []]);
        $adapter = $this->adapter($db, $this->registryConfig());
        $adapter->bindRead('news');
        $this->assertCount(1, $this->reaps(), 'the startup reap');

        $this->assertFalse($adapter->pickTask());

        $this->assertCount(2, $this->reaps(), 'and one per renew, which is per interval not per cycle');
        $this->assertCount(2, $this->insertsInto('backq_workers'));
    }

    /**
     * The two are not one statement and the order is the contract: the lease is written
     * before the reap, so a reap that takes a moment cannot expire the worker's own row
     * out from under it, and the renew happens before begin_transaction() so it holds no
     * row lock while the pick runs.
     */
    public function testTheLeaseIsWrittenBeforeTheReapInTheSameCycle(): void
    {
        $db = $this->db([[]]);
        $adapter = $this->adapter($db, $this->registryConfig());
        $adapter->bindRead('news');

        $this->assertFalse($adapter->pickTask());

        $this->assertStringStartsWith('INSERT INTO backq_workers', $this->statements[2]);
        $this->assertStringStartsWith('DELETE FROM backq_workers', $this->statements[3]);
        $this->assertStringContainsString('FROM backq_jobs', $this->statements[4], 'and the pick ran last');
    }

    /**
     * A lease that cannot be written must not also cost the table its reap, and a reap
     * that fails must not cost the worker its lease. Two attempts, not one: a single
     * attempt() around both would make the first failure skip the second. This is the case
     * a database user without DELETE lands in, which is silent apart from the log line -
     * the leases keep working and the table grows forever.
     */
    public function testAFailedReapDoesNotCostTheWorkerItsLease(): void
    {
        $logger  = new RecordingLogger();
        $denied  = new mysqli_sql_exception('You are not allowed to delete', 1142);
        $db      = $this->db([[], $denied, [], $denied, []], $denied);
        $adapter = $this->adapter($db, $this->registryConfig(), $logger);

        $adapter->bindRead('news');
        $this->assertCount(1, $this->insertsInto('backq_workers'), 'the announce leased');
        $this->assertCount(1, $this->reaps(), 'and the reap was attempted rather than skipped');

        $this->assertFalse($adapter->pickTask());
        $this->assertCount(
            2,
            $this->insertsInto('backq_workers'),
            'and so did the renew, so a full lease of visibility'
        );
        $this->assertCount(2, $this->reaps());
        $this->assertLogged($logger, 'not allowed to delete', 'error');
    }

    /**
     * The registry is never load-bearing. A deployment that granted SELECT, INSERT and
     * UPDATE but not DELETE, or that has not run the DDL at all, must not stop every worker
     * in it, so bindRead() answers true whatever the registry said.
     */
    public function testBindReadStillReturnsTrueWhenTheStatementThrows(): void
    {
        $logger  = new RecordingLogger();
        $db      = $this->db([], new mysqli_sql_exception("Table 'mydb.backq_workers' doesn't exist"));
        $adapter = $this->adapter($db, null, $logger);

        $this->assertTrue($adapter->bindRead('news'));
        $this->assertLogged($logger, "Table 'mydb.backq_workers' doesn't exist", 'error');
    }

    public function testHasWorkersIsTrueWhenALeaseIsLive(): void
    {
        $db = $this->db([['workers' => '1']]);

        $this->assertTrue($this->adapter($db)->hasWorkers('news'));
        $this->assertSame(
            'SELECT COUNT(*) AS workers FROM backq_workers WHERE queue = "news" '
            . 'AND seen > NOW() - INTERVAL 300 SECOND',
            $this->statements[0]
        );
    }

    /**
     * The only guard against an "always true" bug. The stub this replaces answered false for
     * every queue at every time, and a read that cannot see its own WHERE clause looks
     * exactly like that from the outside.
     */
    public function testHasWorkersIsFalseWhenNoLeaseIsLive(): void
    {
        $db = $this->db([['workers' => '0']]);

        $this->assertFalse($this->adapter($db)->hasWorkers('news'));
    }

    public function testHasWorkersIsFalseWhenTheCountQueryFindsNoRowAtAll(): void
    {
        $db = $this->db([[]]);

        $this->assertFalse($this->adapter($db)->hasWorkers('news'));
    }

    /**
     * One table holds every queue's jobs, so the shared job table cannot answer "is a worker
     * on *this* queue" and the registry carries a queue column of its own. Without the
     * predicate a worker on any queue would answer for all of them.
     */
    public function testHasWorkersCountsOnlyTheGivenQueue(): void
    {
        $db      = $this->db([[], []]);
        $adapter = $this->adapter($db);

        $adapter->hasWorkers('news');
        $adapter->hasWorkers('mail');

        $this->assertStringContainsString('WHERE queue = "news"', $this->statements[0]);
        $this->assertStringContainsString('WHERE queue = "mail"', $this->statements[1]);
    }

    public function testHasWorkersIsFalseWhenTheStatementThrows(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Server has gone away'));

        $this->assertFalse($this->adapter($db, null, $logger)->hasWorkers('news'));
        $this->assertLogged($logger, 'Server has gone away', 'error');
    }

    /**
     * The user has to run the DDL, and a deployment that has not must degrade to false rather
     * than raise - but it must not do it silently, and it must not do it once per call. This
     * is the one asymmetry in the feature: the library cannot tell "you forgot the CREATE
     * TABLE" from "the server went away", and a page of error logs per web request is worse
     * than one debug line.
     */
    public function testHasWorkersLogsAMissingRegistryTableOnceAtDebug(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([new mysqli_sql_exception("Table 'mydb.backq_workers' doesn't exist", 1146)]);
        $adapter = $this->adapter($db, null, $logger);

        $this->assertFalse($adapter->hasWorkers('news'));
        $this->assertCount(1, $this->statements);

        $this->assertFalse($adapter->hasWorkers('news'));
        $this->assertCount(1, $this->statements, 'the second call issues no statement at all');
        $this->assertLogged($logger, 'backq_workers', 'debug');
        $this->assertNotLogged($logger, "doesn't exist", 'error');
    }

    /**
     * Only 1146 is the forgotten DDL. Everything else is the server, and a server that is
     * unhappy about something else still gets an error on every call - a false negative
     * dressed as a de-duplicated debug line is the failure this guards against. 1205 is the
     * lock wait timeout, measured rather than quoted: the plan this test came from names
     * 1144, which is a missing storage engine.
     */
    public function testAnUnrelatedStatementFailureStillLogsAtErrorEveryTime(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Lock wait timeout exceeded', 1205));
        $adapter = $this->adapter($db, null, $logger);

        $this->assertFalse($adapter->hasWorkers('news'));
        $this->assertFalse($adapter->hasWorkers('news'));

        $this->assertCount(2, $this->statements, 'no de-duplication outside 1146');
        $this->assertSame(2, $this->levelsAt($logger, 'error'));
    }

    /**
     * A release is keyed by the token the adapter took, so a worker that could not write its
     * release must still not believe in a lease it no longer has
     */
    public function testDisconnectDeletesTheLeaseItWrote(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);
        $adapter->bindRead('news');

        $this->assertTrue($adapter->disconnect());
        $this->assertMatchesRegularExpression(
            '#^DELETE FROM backq_workers WHERE token = UNHEX\("(' . self::TOKEN . ')"\)$#',
            $this->statements[2],
        );
        $this->assertSame($this->tokenIn($this->statements[0]), $this->tokenIn($this->statements[2]));
    }

    public function testDisconnectTouchesNothingWhenBindReadNeverRan(): void
    {
        $db = $this->db();

        $this->assertTrue($this->adapter($db)->disconnect());
        $this->assertSame([], $this->statements);
    }

    /**
     * Every pick cycle runs the renew, so it is rate-limited rather than written per cycle.
     * With the default 300 second lease the interval is 100 seconds and three cycles take
     * microseconds, so "one write" here is a real measurement and not a timing coincidence.
     * The other half - that a renew happens again once the interval has passed - needs the
     * wall clock to move, and it is asserted in MySqlLiveTest with a 5 second lease rather
     * than by sleeping 100 seconds.
     */
    public function testPickTaskRenewsAtMostOncePerThirdOfTheLease(): void
    {
        $db = $this->db([[], [], []]);
        $adapter = $this->adapter($db, $this->registryConfig());
        $adapter->bindRead('news');
        $this->assertCount(1, $this->insertsInto('backq_workers'), 'bindRead announced the worker');

        $this->assertFalse($adapter->pickTask());
        $this->assertCount(
            2,
            $this->insertsInto('backq_workers'),
            'the first pick cycle renews, $renewedAt starts null'
        );
        $this->assertCount(2, $this->reaps(), 'and reaps, on the same throttle');

        $this->assertFalse($adapter->pickTask());
        $this->assertFalse($adapter->pickTask());
        $this->assertCount(2, $this->insertsInto('backq_workers'), 'the next two are inside the interval');
        $this->assertCount(2, $this->reaps(), 'so they reap nothing either, which is the point of a throttle');
    }

    /**
     * A renew that fails is a failed lease, and a worker whose lease is failing is a worker
     * whose pick must keep working. The throttle must not advance on the failed write either,
     * or a transient error would cost a third of the lease length of visibility.
     */
    public function testPickTaskSurvivesAFailedRenewAndRetriesIt(): void
    {
        $logger   = new RecordingLogger();
        $failure  = new mysqli_sql_exception('Lock wait timeout exceeded', 1205);
        $db       = $this->db([null, null, $failure, null, null, null]);
        $adapter  = $this->adapter($db, $this->registryConfig(), $logger);
        $adapter->bindRead('news');

        $this->assertFalse($adapter->pickTask());
        $this->assertCount(5, $this->statements, 'the renew failed, the reap still ran, and the pick still ran');
        $this->assertStringStartsWith('DELETE FROM backq_workers', $this->statements[3]);
        $this->assertStringContainsString('FROM backq_jobs', $this->statements[4]);
        $this->assertLogged($logger, 'Lock wait timeout exceeded', 'error');

        $this->assertFalse($adapter->pickTask());
        $this->assertCount(3, $this->insertsInto('backq_workers'), 'the throttle did not skip the retry');
    }

    public function testSetWorkTimeoutIsIgnored(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();
        $db->expects($this->never())->method('query');

        $this->adapter($db, null, $logger)->setWorkTimeout(5);
        $this->assertLogged($logger, 'setWorkTimeout', 'debug');
    }

    public function testPickTaskLocksTheJobItTook(): void
    {
        $db      = $this->db([['id' => 7, 'payload' => 'serialized']]);
        $adapter = $this->adapter($db);

        $db->expects($this->once())->method('begin_transaction');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $this->assertSame([7, 'serialized'], $adapter->pickTask());

        $this->assertSame(
            "SELECT id, payload FROM backq_jobs WHERE sync = 'WAIT' LIMIT 1 FOR UPDATE",
            $this->statements[0]
        );
        $this->assertMatchesRegularExpression(
            '#^UPDATE backq_jobs SET sync = "LOCK", time_sync = NOW\(\), '
            . 'WHERE id = "7"$#',
            $this->statements[1]
        );
    }

    public function testPickTaskQuotesTheJobIdItLocks(): void
    {
        $db      = $this->db([['id' => "1' OR '1'='1", 'payload' => 'serialized']]);
        $adapter = $this->adapter($db);

        $this->assertSame(["1' OR '1'='1", 'serialized'], $adapter->pickTask());
        $this->assertStringEndsWith('WHERE id = "1\\\' OR \\\'1\\\'=\\\'1"', $this->statements[1]);
    }

    public function testPickTaskReturnsFalseWhenNoJobIsWaiting(): void
    {
        $db      = $this->db([[]]);
        $adapter = $this->adapter($db);

        $db->expects($this->never())->method('commit');

        $this->assertFalse($adapter->pickTask());
        $this->assertCount(1, $this->statements);
    }

    public function testPickTaskRollsBackAndLogsWhenTheSelectFails(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Table does not exist'));
        $db->expects($this->once())->method('rollback');

        $this->assertFalse($this->adapter($db, null, $logger)->pickTask());
        $this->assertStringContainsString('Table does not exist', $this->messages($logger));
    }

    public function testPickTaskRollsBackAndLogsWhenTheLockFails(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([['id' => 7, 'payload' => 'serialized']], new mysqli_sql_exception('Deadlock'));
        $db->expects($this->once())->method('rollback');
        $db->expects($this->never())->method('commit');

        $this->assertFalse($this->adapter($db, null, $logger)->pickTask());
        $this->assertStringContainsString('Deadlock', $this->messages($logger));
    }

    public function testPutTaskWithoutAJobIdReturnsThrowable(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('begin_transaction');

        $this->assertInstanceOf(Throwable::class, $this->adapter($db, null, $logger)->putTask('serialized'));
        $this->assertStringContainsString('Missing job id parameter', $this->messages($logger));
    }

    public function testPutTaskRejectsAnUnusableJobId(): void
    {
        // Values the declared type admits but that cannot name a row. The type rejects
        // everything else on its own, see testPutTaskRejectsAJobIdOfTheWrongType().
        $rejected = [0, '', null];

        foreach ($rejected as $jobId) {
            $db = $this->db();
            $db->expects($this->never())->method('query');

            $this->assertInstanceOf(
                Throwable::class,
                $this->adapter($db)->putTask('serialized', $jobId),
                'a job id of ' . var_export($jobId, true) . ' must be rejected'
            );
        }
    }

    public function testPutTaskRejectsAJobIdOfTheWrongType(): void
    {
        foreach ([[], new stdClass()] as $jobId) {
            $db = $this->db();
            $db->expects($this->never())->method('query');

            try {
                $this->adapter($db)->putTask('serialized', $jobId);
                $this->fail('a job id of ' . get_debug_type($jobId) . ' must raise a TypeError');
            } catch (TypeError $e) {
                $this->assertStringContainsString('$jobId', $e->getMessage());
            }
        }
    }

    public function testPutTaskToForwardsTheJobIdFirst(): void
    {
        $db      = $this->db([[]]);
        $adapter = $this->adapter($db);

        $this->assertSame('a-1', $adapter->putTaskTo('a-1', 'serialized', true));
        $this->assertMatchesRegularExpression('#,"' . JobState::Done->value . '"\)$#', $this->statements[1]);
    }

    public function testPutTaskToRejectsAnUnusableJobId(): void
    {
        $db = $this->db();
        $db->expects($this->never())->method('query');

        $this->assertInstanceOf(Throwable::class, $this->adapter($db)->putTaskTo(0, 'serialized'));
    }

    public function testPutTaskInsertsAnUnknownJob(): void
    {
        $db      = $this->db([[]]);
        $adapter = $this->adapter($db);

        $db->expects($this->once())->method('begin_transaction');
        $db->expects($this->once())->method('commit');

        $this->assertSame('a-1', $adapter->putTask('serialized', 'a-1', noSleep: true));
        $this->assertSame("SELECT id FROM backq_jobs WHERE id = 'a-1' FOR UPDATE", $this->statements[0]);
        $this->assertMatchesRegularExpression(
            '#^INSERT INTO backq_jobs \(id,payload,time_sync,sync\) VALUES '
            . '\("a-1","serialized",NOW\(\),"WAIT"\)$#',
            $this->statements[1]
        );
    }

    public function testPutTaskUpdatesAKnownJob(): void
    {
        $db      = $this->db([['id' => 'a-1']]);
        $adapter = $this->adapter($db);

        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $this->assertSame('a-1', $adapter->putTask('serialized', 'a-1'));
        $this->assertMatchesRegularExpression(
            '#^UPDATE backq_jobs SET payload = "serialized", '
            . 'time_sync = NOW\(\), sync = "WAIT" WHERE id = "a-1"$#',
            $this->statements[1]
        );
    }

    public function testPutTaskStoresTheJobAsDoneWhenAsked(): void
    {
        $db      = $this->db([[]]);
        $adapter = $this->adapter($db);

        $this->assertSame('a-1', $adapter->putTask('serialized', 'a-1', putAsDone: true));
        $this->assertMatchesRegularExpression('#,"' . JobState::Done->value . '"\)$#', $this->statements[1]);
    }

    public function testPutTaskEscapesTheJobIdAndTheBody(): void
    {
        $db      = $this->db([[]]);
        $adapter = $this->adapter($db);

        $adapter->putTask('it\'s a job', 'o\'brien');

        $this->assertStringContainsString('id = \'o\\\'brien\' FOR UPDATE', $this->statements[0]);
        $this->assertStringContainsString('"it\\\'s a job"', $this->statements[1]);
    }

    public function testPutTaskRollsBackAndLogsWhenTheServerRejectsTheStatement(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Unknown column'));
        $db->expects($this->once())->method('rollback');
        $db->expects($this->never())->method('commit');

        $this->assertInstanceOf(
            Throwable::class,
            $this->adapter($db, null, $logger)->putTask('serialized', 'a-1')
        );
        $this->assertStringContainsString('Unknown column', $this->messages($logger));
    }

    public function testPutTaskRollsBackWhenTheCommitFails(): void
    {
        $db = $this->db([[]]);
        $db->method('commit')->willThrowException(new mysqli_sql_exception('Lost connection'));
        $db->expects($this->once())->method('rollback');

        $this->assertInstanceOf(Throwable::class, $this->adapter($db)->putTask('serialized', 'a-1'));
    }

    public function testPutTaskToleratesAFailedRollback(): void
    {
        $db = $this->db([], new mysqli_sql_exception('Unknown column'));
        $db->method('rollback')->willThrowException(new mysqli_sql_exception('Server has gone away'));

        $logger = new RecordingLogger();

        $this->assertInstanceOf(
            Throwable::class,
            $this->adapter($db, null, $logger)->putTask('serialized', 'a-1')
        );
        $this->assertStringContainsString('could not be rolled back', $this->messages($logger));
    }

    public function testAfterWorkSuccessMovesTheJobToDone(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->afterWorkSuccess('7'));
        $this->assertSame('UPDATE backq_jobs SET sync = "DONE" WHERE id = "7"', $this->statements[0]);
    }

    public function testAfterWorkFailedMovesTheJobToHold(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->afterWorkFailed('7'));
        $this->assertSame('UPDATE backq_jobs SET sync = "HOLD" WHERE id = "7"', $this->statements[0]);
    }

    public function testAnAckAcceptsANonNumericJobId(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->afterWorkSuccess('a-1'));
        $this->assertSame('UPDATE backq_jobs SET sync = "DONE" WHERE id = "a-1"', $this->statements[0]);
    }

    public function testAnAckWithoutAJobIdFails(): void
    {
        $logger  = new RecordingLogger();
        $db      = $this->db();
        $adapter = $this->adapter($db, null, $logger);
        $db->expects($this->never())->method('query');

        $this->assertFalse($adapter->afterWorkSuccess(null));
        $this->assertFalse($adapter->afterWorkFailed(null));
        $this->assertStringContainsString('Missing job id', $this->messages($logger));
    }

    public function testAnAckFailsInsteadOfThrowingWhenTheServerRejectsIt(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Lock wait timeout'));

        $this->assertFalse($this->adapter($db, null, $logger)->afterWorkSuccess('7'));
        $this->assertStringContainsString('Lock wait timeout', $this->messages($logger));
    }

    public function testAnAckSucceedsWhenTheStatementMatchesNoRow(): void
    {
        // The link double answers every statement with a result set, so the adapter reads
        // 0 affected rows. The honest reading of that cell is "the statement executed",
        // not "a row changed", because an UPDATE that writes the value a row already holds
        // also reports 0. The id is logged so the case is not silent.
        $logger  = new RecordingLogger();
        $db      = $this->db();
        $adapter = $this->adapter($db, null, $logger);

        $this->assertTrue($adapter->afterWorkSuccess('7'));
        $this->assertLogged($logger, 'no row matched 7', 'debug');
    }

    public function testTheFailureIsReportedWithTheExceptionInTheContext(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db([], new mysqli_sql_exception('Lock wait timeout'));

        $this->assertFalse($this->adapter($db, null, $logger)->afterWorkSuccess('7'));
        $this->assertLoggedException($logger, mysqli_sql_exception::class);
    }

    public function testTheConfiguredTableAndColumnsAreUsed(): void
    {
        $db      = $this->db([['uid' => 7, 'body' => 'serialized']]);
        $config  = new JobConfig('uid', 'body', 'queue', 0, 0, 0);
        $adapter = $this->adapter($db, $config);

        $this->assertSame([7, 'serialized'], $adapter->pickTask());
        $this->assertSame("SELECT uid, body FROM queue WHERE sync = 'WAIT' LIMIT 1 FOR UPDATE", $this->statements[0]);
        $this->assertMatchesRegularExpression('#^UPDATE queue SET sync = "LOCK"#', $this->statements[1]);

        $this->assertTrue($adapter->afterWorkSuccess('7'));
        $this->assertSame('UPDATE queue SET sync = "DONE" WHERE uid = "7"', $this->statements[2]);
    }

    public function testTheStatementsAreLoggedForDebugging(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();

        $this->adapter($db, null, $logger)->afterWorkSuccess('7');

        $this->assertContains('debug', array_column($logger->records, 0));
        $this->assertStringContainsString('UPDATE backq_jobs SET sync = "DONE"', $this->messages($logger));
    }

    public function testTheLoggerIsMandatory(): void
    {
        /**
         * Called through reflection: the helper would supply a NullLogger, and a direct
         * two-argument call is exactly the mistake this test exists to make impossible
         */
        $adapter = (new ReflectionClass(MySql::class))->newInstanceWithoutConstructor();

        $this->expectException(ArgumentCountError::class);

        (new ReflectionMethod(MySql::class, '__construct'))->invoke($adapter);
    }

    /**
     * Build a link double
     *
     * Every statement is answered with a result set, so the adapter never reads the
     * read-only `affected_rows` property of the driver and the recorded SQL is the
     * only thing these tests have to reason about.
     *
     * @param array<int, array<mixed>|Throwable> $answers what each statement answers with: a row,
     *                                                      a list of rows or a failure to throw
     * @param Throwable|null $defaultFailure the failure of every statement not answered above
     */
    private function db(array $answers = [], ?Throwable $defaultFailure = null): mysqli
    {
        $this->statements     = [];
        $this->answers        = $answers;
        $this->defaultFailure = $defaultFailure;

        $db = $this->createMock(mysqli::class);
        $db->method('real_escape_string')->willReturnCallback(
            static function (string $value): string {
                return str_replace("'", "\\'", $value);
            }
        );
        $db->method('query')->willReturnCallback(
            function (string $sql): mysqli_result {
                $this->statements[] = $sql;
                $answer            = array_shift($this->answers);

                if ($answer instanceof Throwable) {
                    throw $answer;
                }

                if (null === $answer && $this->defaultFailure instanceof Throwable) {
                    throw $this->defaultFailure;
                }

                $result = $this->createMock(mysqli_result::class);
                $result->method('fetch_all')->willReturn(
                    is_array($answer) && !array_is_list($answer) ? [$answer] : ($answer ?? [])
                );

                return $result;
            }
        );

        return $db;
    }

    private function adapter(mysqli $db, ?JobConfig $config = null, ?LoggerInterface $logger = null): MySql
    {
        return new MySql(
            $db,
            $config ?? $this->config(),
            $logger ?? new NullLogger()
        );
    }

    /**
     * The documented table without the sleeps that would slow the suite down
     *
     * @param Closure():mysqli|null $provider builds the link that replaces a dead one
     */
    private function config(?Closure $provider = null): JobConfig
    {
        return new JobConfig('id', 'payload', 'backq_jobs', 0, 0, 0, $provider);
    }

    /**
     * The same, with the worker registry the hasWorkers() tests read
     *
     * Built with named arguments so the registry fields are named where they are read, and
     * the positional seven above are left to prove the fields were appended rather than
     * inserted.
     */
    private function registryConfig(int $workerTtl = 300): JobConfig
    {
        return new JobConfig(
            idColumn: 'id',
            dataColumn: 'payload',
            table: 'backq_jobs',
            pickMissSleep: 0,
            pickSuccessSleep: 0,
            putTaskSleep: 0,
            workerTable: 'backq_workers',
            workerTtl: $workerTtl,
        );
    }

    /**
     * The recorded INSERTs naming one table
     *
     * @return list<string>
     */
    private function insertsInto(string $table): array
    {
        return array_values(array_filter(
            $this->statements,
            static function (string $sql) use ($table): bool {
                return str_starts_with($sql, 'INSERT INTO ' . $table . ' ');
            }
        ));
    }

    /**
     * The recorded reaps, which is the count that says whether the table stays bounded
     *
     * @return list<string>
     */
    private function reaps(): array
    {
        return array_values(array_filter(
            $this->statements,
            static function (string $sql): bool {
                return str_starts_with($sql, 'DELETE FROM backq_workers ');
            }
        ));
    }

    /**
     * The token out of a lease or a release, so two statements can be compared as being
     * about the same worker without the assertion repeating the token's shape
     */
    private function tokenIn(string $sql): string
    {
        $matched = preg_match('#UNHEX\("(' . self::TOKEN . ')"\)#', $sql, $found);
        $this->assertSame(1, $matched, 'the statement names a token: ' . $sql);

        return $found[1];
    }

    /**
     * The 48 bits of milliseconds out of the front of a version 7 token
     *
     * The first six bytes are the timestamp big-endian, so the first twelve hex
     * characters are the whole of it and hexdec() handles the rest. This is the decoder
     * the adapter's layout is written against, so a layout that changed would have to
     * change this too - which is why the test above asserts the decoded value rather than
     * the first twelve characters.
     */
    private function millisIn(string $token): int
    {
        return (int) hexdec(substr($token, 0, 12));
    }

    /**
     * @return int how many records the logger captured at one level
     */
    private function levelsAt(RecordingLogger $logger, string $level): int
    {
        return count(array_filter(
            $logger->records,
            static function (array $record) use ($level): bool {
                return $record[0] === $level;
            }
        ));
    }

    private function messages(RecordingLogger $logger): string
    {
        return implode("\n", array_column($logger->records, 1));
    }
}
