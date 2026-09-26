<?php

namespace BackQ\Tests\Worker;

use BackQ\Message\Process;
use BackQ\Tests\Support\ProcessExpiredMessage;
use BackQ\Tests\Support\ProcessNotReadyMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\AProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function array_column;
use function file_exists;
use function file_get_contents;
use function implode;
use function ini_get;
use function ini_set;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function tempnam;
use function time;
use function unlink;
use const E_USER_WARNING;
use const PHP_BINARY;

class AProcessWorkerTest extends TestCase
{
    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [13, 'not-a-process-message'];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
            if (file_exists($errorLog)) {
                unlink($errorLog);
            }
        }

        $this->assertContains(['afterWorkSuccess', 13], $adapter->calls);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testEmptyPayloadIsSkipped(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = false;

        $worker = new AProcess($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertSame(
            [
                ['setWorkTimeout', 5],
                'connect',
                ['bindRead', 'process'],
                ['pickTask', null],
                'disconnect',
            ],
            $adapter->calls
        );
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [12, 12345];

        $worker = new AProcess($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertContains(['afterWorkSuccess', 12], $adapter->calls);
    }

    public function testProcessesArrayCommandline(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [20, serialize(new Process([PHP_BINARY, '-r', 'echo 1;']))];

        $logger = $this->runWorker($adapter, 1, new RecordingLogger());

        $this->assertContains(['afterWorkSuccess', 20], $adapter->calls);
        $this->assertContains('disconnect', $adapter->calls);

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('reporting work as processed: true', $wholeLog);
    }

    public function testProcessesShellCommandline(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [21, serialize(new Process(PHP_BINARY . ' -r "echo 1;"'))];

        $this->runWorker($adapter);

        $this->assertContains(['afterWorkSuccess', 21], $adapter->calls);
    }

    public function testSkipsTaskBeyondDeadline(): void
    {
        $message = new Process([PHP_BINARY, '-r', 'exit(0);']);
        $message->setDeadline(time() - 1000);

        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [22, serialize($message)];

        $this->runWorker($adapter);

        $this->assertContains(['afterWorkSuccess', 22], $adapter->calls);
    }

    public function testDefersNotReadyMessage(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [23, serialize(new ProcessNotReadyMessage())];
        $adapter->afterWorkFailedResult = true;

        $this->runWorker($adapter);

        $this->assertContains(['afterWorkFailed', 23], $adapter->calls);
    }

    public function testDiscardsExpiredMessageAsSuccess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [24, serialize(new ProcessExpiredMessage())];
        $adapter->afterWorkFailedResult = true;

        $this->runWorker($adapter);

        $this->assertContains(['afterWorkSuccess', 24], $adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 24], $adapter->calls);
    }

    public function testTriggersWarningOnNonZeroExitCode(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [25, serialize(new Process([PHP_BINARY, '-r', 'exit(3);']))];

        $warning = null;
        set_error_handler(static function ($no, $str) use (&$warning): bool {
            $warning = $str;

            return true;
        }, E_USER_WARNING);

        try {
            $this->runWorker($adapter, 3);
        } finally {
            restore_error_handler();
        }

        $this->assertIsString($warning);
        $this->assertStringContainsString('existed with error code 3', $warning);
        $this->assertContains(['afterWorkSuccess', 25], $adapter->calls);
    }

    public function testManagesRunningForkAndCleansUp(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [26, serialize(new Process([PHP_BINARY, '-r', 'usleep(400000);']))];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(2);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringNotContainsString('Process worker exception', $loggedErrors);
        $this->assertStringNotContainsString('Process worker failed to stop forked child', $loggedErrors);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testCleanupStopsSigintIgnoringChild(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [27, serialize(new Process([
            PHP_BINARY,
            '-r',
            'pcntl_signal(SIGINT, function(){}); pcntl_async_signals(true); usleep(5000000);',
        ]))];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringNotContainsString('Process worker failed to stop forked child', $loggedErrors);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testCatchesProcessTimedOutDuringManageForks(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [28, serialize(
            new Process([PHP_BINARY, '-r', 'usleep(500000);'], null, null, null, 0.01)
        )];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $this->runWorker($adapter, 3);
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('Process worker caught ProcessTimedOutException', $loggedErrors);
    }

    public function testWarnsWhenProcessKilledBySignalLeavesExitCode(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [29, serialize(new Process([
            PHP_BINARY,
            '-r',
            'usleep(50000); posix_kill(getmypid(), SIGKILL);',
        ]))];

        $warning = null;
        set_error_handler(static function ($no, $str) use (&$warning): bool {
            $warning = $str;

            return true;
        }, E_USER_WARNING);

        try {
            $this->runWorker($adapter, 3);
        } finally {
            restore_error_handler();
        }

        $this->assertIsString($warning);
        $this->assertStringContainsString('existed with error code 137', $warning);
        $this->assertContains(['afterWorkSuccess', 29], $adapter->calls);
    }

    public function testAckFailureTriggersOuterCatch(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [30, 'garbage'];
        $adapter->afterWorkSuccessResult = false;

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('Process worker exception', $loggedErrors);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testLogsFailureToLaunchProcess(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [31, serialize(
            new Process([PHP_BINARY, '-r', 'exit(0);'], '/nonexistent/dir-no-such')
        )];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(1);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('Process worker failed to run', $loggedErrors);
        $this->assertContains(['afterWorkSuccess', 31], $adapter->calls);
        $this->assertContains('disconnect', $adapter->calls);
    }

    public function testCleanupLogsTimeoutFailureToStopChild(): void
    {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [32, serialize(
            new Process([PHP_BINARY, '-r', 'usleep(500000);'], null, null, null, 0.05)
        )];

        $errorLog = tempnam(sys_get_temp_dir(), 'backqerr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $worker = new AProcess($adapter);
            $worker->setLogger(new NullLogger());
            $worker->setRestartThreshold(2);

            $worker->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('Process worker failed to stop forked child', $loggedErrors);
        $this->assertContains('disconnect', $adapter->calls);
    }

    /**
     * @param int $restartThreshold
     */
    private function runWorker(
        TestAdapter $adapter,
        int $restartThreshold = 1,
        ?LoggerInterface $logger = null,
    ): LoggerInterface {
        $logger ??= new NullLogger();
        $worker = new AProcess($adapter);
        $worker->setLogger($logger);
        $worker->setRestartThreshold($restartThreshold);

        $worker->run();

        return $logger;
    }
}
