<?php

namespace BackQ\Tests;

use BackQ\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'backqtest_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testSkipsInfoUnlessDebug(): void
    {
        $logger = new Logger($this->logFile);
        $logger->log('INFO: something happened');

        $this->assertSame('', (string) file_get_contents($this->logFile));
    }

    public function testSkipsStatusUnlessDebug(): void
    {
        $logger = new Logger($this->logFile);
        $logger->log('STATUS: connecting');

        $this->assertSame('', (string) file_get_contents($this->logFile));
    }

    public function testWritesErrorMessages(): void
    {
        $logger = new Logger($this->logFile);
        $logger->log('ERROR: boom');

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('ERROR: boom', $contents);
    }

    public function testWritesInfoInDebugMode(): void
    {
        $logger = new Logger($this->logFile);
        $logger->log('INFO: something happened', true);

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('INFO: something happened', $contents);
    }

    public function testTrimsMessage(): void
    {
        $logger = new Logger($this->logFile);
        $logger->log('ERROR: padded   ');

        $this->assertStringNotContainsString('ERROR: padded   ', (string) file_get_contents($this->logFile));
        $this->assertStringContainsString('ERROR: padded', (string) file_get_contents($this->logFile));
    }
}