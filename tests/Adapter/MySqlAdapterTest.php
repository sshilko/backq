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
use function array_is_list;
use function array_shift;
use function get_debug_type;
use function implode;
use function is_array;
use function str_replace;
use function var_export;

class MySqlAdapterTest extends TestCase
{

    use LogAssertions;

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

    public function testTheQueueNameIsIrrelevantTheTableIsTheQueue(): void
    {
        $db = $this->db();
        $db->expects($this->never())->method('query');
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->bindRead('whatever'));
        $this->assertTrue($adapter->bindWrite('whatever'));
    }

    /**
     * A table queue has no heartbeat column, so there is no cheap way to know whether a
     * worker is idle on it. Answering "yes" reaches the user through
     * AbstractPublisher::hasWorkers(), so the honest answer is no.
     */
    public function testHasWorkersReportsNoWorkersRatherThanClaimingSome(): void
    {
        $logger  = new RecordingLogger();
        $db      = $this->db();
        $adapter = $this->adapter($db, null, $logger);

        $this->assertFalse($adapter->hasWorkers('whatever'));
        $this->assertLogged($logger, 'hasWorkers', 'debug');
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

    private function messages(RecordingLogger $logger): string
    {
        return implode("\n", array_column($logger->records, 1));
    }
}
