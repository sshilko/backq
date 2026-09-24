<?php

namespace BackQ\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures every log record so tests can assert on log output.
 */
class RecordingLogger extends AbstractLogger
{

    /**
     * @var array<int, array{0: mixed, 1: string, 2: array}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
