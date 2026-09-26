<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\MySql\JobConfig;
use BackQ\Adapter\MySql\JobState;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestMySqlAdapter;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    public function testTheQueueNameIsIrrelevantTheTableIsTheQueue(): void
    {
        $db = $this->db();
        $db->expects($this->never())->method('query');
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->bindRead('whatever'));
        $this->assertTrue($adapter->bindWrite('whatever'));
        $this->assertTrue($adapter->hasWorkers('whatever'));
    }

    public function testSetWorkTimeoutIsIgnored(): void
    {
        $db = $this->db();
        $db->expects($this->never())->method('query');

        $this->adapter($db)->setWorkTimeout(5);
        $this->addToAssertionCount(1);
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
            '#^UPDATE backq_jobs SET sync = "LOCK", time_sync = "\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" '
            . 'WHERE id = 7$#',
            $this->statements[1]
        );
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
            . '\("a-1","serialized","\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}","WAIT"\)$#',
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
            . 'time_sync = "\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}", sync = "WAIT" WHERE id = "a-1"$#',
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

        $this->assertTrue($adapter->afterWorkSuccess(7));
        $this->assertSame('UPDATE backq_jobs SET sync = "DONE" WHERE id = "7"', $this->statements[0]);
    }

    public function testAfterWorkFailedMovesTheJobToHold(): void
    {
        $db      = $this->db();
        $adapter = $this->adapter($db);

        $this->assertTrue($adapter->afterWorkFailed(7));
        $this->assertSame('UPDATE backq_jobs SET sync = "HOLD" WHERE id = "7"', $this->statements[0]);
    }

    public function testAnAckAcceptsAStringJobId(): void
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

        $this->assertFalse($this->adapter($db, null, $logger)->afterWorkSuccess(7));
        $this->assertStringContainsString('Lock wait timeout', $this->messages($logger));
    }

    public function testTheConfiguredTableAndColumnsAreUsed(): void
    {
        $db      = $this->db([['uid' => 7, 'body' => 'serialized']]);
        $config  = new JobConfig('uid', 'body', 'queue', 0, 0, 0);
        $adapter = $this->adapter($db, $config);

        $this->assertSame([7, 'serialized'], $adapter->pickTask());
        $this->assertSame("SELECT uid, body FROM queue WHERE sync = 'WAIT' LIMIT 1 FOR UPDATE", $this->statements[0]);
        $this->assertMatchesRegularExpression('#^UPDATE queue SET sync = "LOCK"#', $this->statements[1]);

        $this->assertTrue($adapter->afterWorkSuccess(7));
        $this->assertSame('UPDATE queue SET sync = "DONE" WHERE uid = "7"', $this->statements[2]);
    }

    public function testTheStatementsAreLoggedForDebugging(): void
    {
        $logger = new RecordingLogger();
        $db     = $this->db();

        $this->adapter($db, null, $logger)->afterWorkSuccess(7);

        $this->assertContains('debug', array_column($logger->records, 0));
        $this->assertStringContainsString('UPDATE backq_jobs SET sync = "DONE"', $this->messages($logger));
    }

    public function testNothingIsLoggedWithoutALogger(): void
    {
        $db = $this->db([], new mysqli_sql_exception('boom'));

        $this->assertFalse($this->adapter($db)->afterWorkSuccess(7));
        $this->addToAssertionCount(1);
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

    private function adapter(mysqli $db, ?JobConfig $config = null, ?LoggerInterface $logger = null): TestMySqlAdapter
    {
        return new TestMySqlAdapter(
            $db,
            $config ?? $this->config(),
            $logger
        );
    }

    /**
     * The documented table without the sleeps that would slow the suite down
     */
    private function config(): JobConfig
    {
        return new JobConfig('id', 'payload', 'backq_jobs', 0, 0, 0);
    }

    private function messages(RecordingLogger $logger): string
    {
        return implode("\n", array_column($logger->records, 1));
    }
}
