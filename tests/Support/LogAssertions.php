<?php

namespace BackQ\Tests\Support;

use function array_filter;
use function sprintf;
use function str_contains;

/**
 * Asserts on what a RecordingLogger captured, so a test reads as "the worker
 * logged this" instead of as a filter over a temp file.
 */
trait LogAssertions
{

    /**
     * @param RecordingLogger $logger the logger the worker was given
     * @param string          $needle substring the message must contain
     * @param string          $level  PSR-3 level to match
     */
    protected function assertLogged(RecordingLogger $logger, string $needle, string $level = 'error'): void
    {
        $this->assertMatchesLog($logger, $needle, $level, true);
    }

    /**
     * @param RecordingLogger $logger the logger the worker was given
     * @param string          $needle substring the message must not contain
     * @param string          $level  PSR-3 level to match
     */
    protected function assertNotLogged(RecordingLogger $logger, string $needle, string $level = 'error'): void
    {
        $this->assertMatchesLog($logger, $needle, $level, false);
    }

    private function assertMatchesLog(RecordingLogger $logger, string $needle, string $level, bool $expected): void
    {
        $matches = array_filter(
            $logger->records,
            static function (array $record) use ($level, $needle): bool {
                return $record[0] === $level && str_contains($record[1], $needle);
            }
        );

        self::assertSame(
            $expected,
            [] !== $matches,
            sprintf('expected %s a %s record containing "%s"', $expected ? '' : 'not', $level, $needle)
        );
    }
}
