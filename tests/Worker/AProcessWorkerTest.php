<?php

namespace BackQ\Tests\Worker;

use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\AProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
            $worker->setTriggerErrorOnError(false);
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
}