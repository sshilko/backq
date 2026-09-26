# Plan 6 — Deprecate every `error_log()` call in `src/`

> Status: **proposed** — design chosen (route to PSR-3, level `error`). Not implemented yet.
> Scope: the 9 `@error_log()` call sites in 4 files, the `logError()` helper that replaces them, the
> 12 tests that capture the PHP error log, the phpcs rule that stops the pattern from coming back,
> and the `UPGRADING` entry.
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`, `plan-4-minor-notes-behavior.md`,
> `plan-5-put-task-contract.md`.

## The problem

`src/` writes 9 messages to the PHP error log with `@error_log()`. Every other diagnostic in the
library already goes through PSR-3. The two paths are not connected, and the `error_log()` one is
the weaker of the two.

| Property | `@error_log()` (today) | `logError()` (the rest of the library) |
|---|---|---|
| Honours `setLogger()` | no — bypasses the worker entirely | yes |
| Reachable in tests | only by `ini_set('error_log', ...)` + `tempnam` + `file_get_contents` | only by injecting a `RecordingLogger` |
| Carries the exception object | no — `getMessage()` string only | yes, via the PSR-3 `context` array |
| Fails quietly when the log is unset | no | yes — `logError()` is a no-op when `$this->logger === null` |
| Respects log level / handlers | no | yes |

Three concrete costs:

1. **The severity signal is lost.** A process worker that cannot launch a child, or an SNS worker
   whose queue ack failed, writes to the same undifferentiated stream as a notice. Nothing in
   `src/` routes these through the `error` level, so a user who configures a PSR-3 handler to alert
   on `error` sees nothing.
2. **The tests are expensive and fragile.** 12 tests across 5 files each allocate a temp file, mutate
   global INI state (`ini_set('error_log', ...)`), restore it in a `finally`, read the file, and
   `unlink` it. Global INI mutation is not restored if the test process dies, and it is invisible to
   every other test running in the same process.
3. **Two of those 12 tests already assert on nothing.** `src/Worker/Guzzle.php` has no
   `error_log()` call at all — its inner `catch (Throwable $e)` is `{ $processed = false; }`
   (`Guzzle.php:122-123`). Plan 2's written text (`plan-2-network-ssl-disconnects.md:142`) did keep
   `error_log('Error while sending FCM: ' . $e->getMessage())` in that catch, so the call was
   dropped during implementation — and the two error-log capture blocks in
   `tests/Worker/GuzzleWorkerTest.php` were left behind asserting on the stream it used to feed.
   They assert `assertStringNotContainsString('Error while sending FCM', $loggedErrors)` and
   `assertSame('', $loggedErrors)` against a file nothing in the library can write to any more.
   They pass unconditionally and will keep passing forever, whatever `Guzzle.php` does.

## The design

Route every site to `AbstractWorker::logError()`. No new class, no new dependency, no new
configuration: all 4 files already extend `AbstractWorker` (`AProcess` directly; the SNS trio via
`PlatformEndpoint` → `Application` → `AbstractWorker`), and `AbstractWorker::__construct()` already
installs a `ConsoleLogger` as the default, so a worker that logs is never a worker that throws.

This is the idiom the rest of the library already uses — 30+ `logError()` call sites in
`Adapter/{Redis,Nsq,Beanstalk,DynamoSQS,MySql}.php` and `Worker/{Serialized,Closure}.php`. The
outlier is the bug.

Level stays `error`. Note that `Worker/Serialized.php:60` logs the *same* situation
(`'Worker does not support payload of: '`) through `logError()`, so `AProcess.php:75` becomes
consistent rather than changing severity.

The `date('Y-m-d H:i:s')` prefix on the three SNS messages is dropped. PSR-3 handlers add their own
timestamp, and hand-built prefixes are what forced the `use function date;` import into three files
that now have no other use for it.

## Out of scope (considered and deliberately excluded)

These are adjacent to the 9 sites but are **not** `error_log()`, so changing them would widen a
deprecation PR into a severity-policy change. Record them; fix them separately.

- **`trigger_error()` sites.** `AProcess.php:250` (child exited non-zero) and
  `GuzzleForwarder.php:125` (per-job request failure) both raise `E_USER_WARNING`. This is a
  different mechanism with a different contract — a `set_error_handler` can convert it into a
  throwable, `error_log()` was never involved — and two `AProcessWorkerTest` cases pin it
  (`testTriggersWarningOnNonZeroExitCode`, `testWarnsWhenProcessKilledBySignalLeavesExitCode`).
- **The same hand-built `date()` prefix, at a different level.** `Guzzle.php:133` and
  `GuzzleForwarder.php:135` both log the outer `catch (Throwable)` as
  `logDebug('[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . ...)`. That is the identical shape item 6.3
  removes from the SNS trio, but it is `logDebug` and not `error_log`, and whether a hard outer-loop
  failure *should* be at `debug` is the question Plan 2 left open for `Guzzle.php`. Fix the level
  first, then the prefix.
- **`BackQ\Logger`** (`src/Logger.php`) — see item 6.7.
- **`build/php.ini`'s `error_log=/dev/null`** — see item 6.6.

## How to execute this plan

Same two-step ritual as Plans 1–4: **Phase A** (rewrite the 12 tests to assert on a PSR-3 logger;
RED against current `src/`), then **Phase B** (swap the 9 call sites; tests GREEN). Phase A is
unusually large here and Phase B unusually small — that is fine, the ordering is what proves the
tests observe the new sink rather than the old one.

Item 6.5 (the phpcs rule) is **Phase B**: adding it before the source change turns 9 real findings
into 9 blocking ones and there is nothing useful to look at. Add it last, then confirm it is quiet.

Item 6.7 (the file logger) is **separable** — it is a convenience for the migration path, not a
correctness requirement. It can be a second PR. Items 6.1–6.6 are the deprecation.

---

## 6.1 `AbstractWorker::logError()` gains the PSR-3 `$context` parameter

**Location:** `src/Worker/AbstractWorker.php:151`

```php
    /**
     * @param string $message
     */
    public function logError(string $message): void
    {
        if (isset($this->logger)) {
            $this->logger->error($message);
        }
    }
```

The replacement sites are all `catch` blocks, and a concatenated `getMessage()` throws away the
type, the code and the `previous` chain. `Psr\Log\LoggerInterface::error()` (verified in the
`app-php83` container against the installed `psr/log` 3.x) is
`error(string|Stringable $message, array $context = []): void`, and PSR-3 reserves the `exception`
key for exactly this. Widen the helper to match:

```php
    /**
     * @param string               $message
     * @param array<string, mixed> $context
     */
    public function logError(string $message, array $context = []): void
    {
        if (isset($this->logger)) {
            $this->logger->error($message, $context);
        }
    }
```

Signature rules verified on PHP 8.3 — adding a **trailing optional** parameter to a concrete method
is legal, and it stays legal for any subclass.

**Why this is safe here, verified by grep across `src/`, `tests/` and `example/`:** nothing
overrides `logError()`, `logInfo()` or `logDebug()`. The only definitions are
`AbstractWorker::log{Error,Info,Debug}()` and `AbstractAdapter::log{Error,Info,Debug}()`. There is no
inherited signature to become incompatible with, so this is not the fatal
`must be compatible with` case that `build/check-classes.php` exists to catch.

Scope: `logError()` only. `logInfo()` / `logDebug()` are left alone — no call site in this plan
needs a context array, and `AbstractAdapter` is left alone entirely. Widen the other two when
something needs them.

---

## 6.2 `AProcess` — 6 call sites → `logError()`

**Location:** `src/Worker/AProcess.php`, lines 75, 181, 188, 218, 259, 261. Remove
`use function error_log;` (line 18). `use function gettype;` stays — line 75 still needs it.

| Line | Replacement |
|---|---|
| 75 | `$this->logError('Worker does not support payload of: ' . gettype($message));` |
| 181 | `$this->logError('Process worker failed to run: ' . $e->getMessage(), ['exception' => $e]);` |
| 188 | `$this->logError('Process worker exception: ' . $e->getMessage(), ['exception' => $e]);` |
| 218 | `$this->logError('Process worker failed to stop forked child: ' . $e->getMessage(), ['exception' => $e]);` |
| 259 | `$this->logError('Process worker caught ProcessTimedOutException: ' . $e->getMessage(), ['exception' => $e]);` |
| 261 | `$this->logError('Process worker caught ProcessSignaledException: ' . $e->getMessage(), ['exception' => $e]);` |

Line 75 is not inside a `catch` — it has no exception, so no context. Lines 259 and 261 are two
`catch` clauses on `ProcessTimedOutException` / `ProcessSignaledException`; both get the context.

`AProcess` is `final`, so there is no subclass to consider. It is in the same namespace as
`AbstractWorker` (`BackQ\Worker`) and already extends it, so `$this->logError()` needs no import.

Note what is **not** changing: `manageForks()` still uses `trigger_error(..., E_USER_WARNING)` for a
non-zero exit code (line 250), and two tests pin that behaviour via `set_error_handler`
(`testTriggersWarningOnNonZeroExitCode`, `testWarnsWhenProcessKilledBySignalLeavesExitCode`).
`trigger_error()` is a different mechanism with a different contract — it is a PHP-level warning a
`set_error_handler` can convert into an exception, and `error_log()` was never involved. Leave it.

---

## 6.3 The SNS trio — 3 call sites → `logError()`, plus import cleanup

**Locations:** `PlatformEndpoint/Publish.php:168`, `Register.php:163`, `Remove.php:168`. All three
are the outer `catch (Throwable $e)` around the work loop, and all three are shaped identically.

`Publish.php`:

```php
            } catch (Throwable $e) {
                @error_log('[' . date('Y-m-d H:i:s') . '] SNS worker exception: ' . $e->getMessage());
            }
```

becomes

```php
            } catch (Throwable $e) {
                $this->logError('SNS worker exception: ' . $e->getMessage(), ['exception' => $e]);
            }
```

`Register.php` and `Remove.php` are the same edit with their own prefixes
(`'Register SNS worker exception: '`, `'Remove endpoints worker exception: '`).

**Import cleanup, all three files.** Remove `use function error_log;` (line 20) **and**
`use function date;` (line 19). Verified by grep over the `PlatformEndpoint/` directory: `date()`
appears on exactly these three lines and nowhere else in the files, so leaving the import trips
`SlevomatCodingStandard.Namespaces.UnusedUses` in the phpcs sweep. `use function gettype;` stays.

`$this->logError()` resolves through `PlatformEndpoint` → `Application` → `AbstractWorker`, all in
`BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint` / `BackQ\Worker\Amazon\SNS\Application`, so
the inherited public method is visible with no import.

Test impact: the three SNS tests assert `assertStringContainsString('SNS worker exception', ...)`
and friends — a substring match that survives dropping the `date()` prefix.

---

## 6.4 Tests — 12 tests stop reading the PHP error log

Add the shared helper first, so the 12 rewrites are one line each instead of a copy-pasted filter:

```php
<?php

namespace BackQ\Tests\Support;

use function array_filter;
use function sprintf;
use function str_contains;

trait LogAssertions
{
    protected function assertLogged(RecordingLogger $logger, string $needle, string $level = 'error'): void
    {
        $this->assertMatchesLog($logger, $needle, $level, true);
    }

    protected function assertNotLogged(RecordingLogger $logger, string $needle, string $level = 'error'): void
    {
        $this->assertMatchesLog($logger, $needle, $level, false);
    }

    private function assertMatchesLog(RecordingLogger $logger, string $needle, string $level, bool $expected): void
    {
        $matches = array_filter(
            $logger->records,
            static fn (array $record): bool => $record[0] === $level && str_contains($record[1], $needle)
        );

        self::assertSame(
            $expected,
            [] !== $matches,
            sprintf('expected %s a %s record containing "%s"', $expected ? '' : 'not', $level, $needle)
        );
    }
}
```

`RecordingLogger::log()` already stores `[$level, (string) $message, $context]`, so this needs no
change to the recorder. Order the `use function` imports alphabetically — the phpcs ruleset enforces
it.

Then convert each test. The pattern is always the same three edits: delete the `tempnam` /
`ini_get` / `ini_set` / `finally` / `file_get_contents` / `unlink` block, pass a `RecordingLogger`
where the test passed `new NullLogger()`, and swap the assertion.

**Why Phase A is RED:** every one of these tests asserts that a message reached the PHP error log.
Against current `src/`, the worker writes to `error_log()` and the `RecordingLogger` stays empty, so
each positive assertion fails. That is the proof the test is observing the new sink.

| File | Test | Today | After |
|---|---|---|---|
| `tests/Worker/AProcessWorkerTest.php` | `testRejectsUnsupportedPayloadAsSuccess` (L31) | error-log block, **no assertion on it** | delete the block; add `assertLogged($logger, 'Worker does not support payload of')` — pins line 75, currently unpinned |
| same | `testManagesRunningForkAndCleansUp` (L177) | `assertStringNotContainsString` ×2 on the error log | `assertNotLogged($logger, 'Process worker exception')`, `assertNotLogged($logger, 'Process worker failed to stop forked child')` |
| same | `testCleanupStopsSigintIgnoringChild` (L204) | `assertStringNotContainsString` | `assertNotLogged($logger, 'Process worker failed to stop forked child')` |
| same | `testCatchesProcessTimedOutDuringManageForks` (L234) | `assertStringContainsString` | `assertLogged($logger, 'Process worker caught ProcessTimedOutException')` |
| same | `testAckFailureTriggersOuterCatch` (L284) | `assertStringContainsString` | `assertLogged($logger, 'Process worker exception')` |
| same | `testLogsFailureToLaunchProcess` (L311) | `assertStringContainsString` | `assertLogged($logger, 'Process worker failed to run')` |
| same | `testCleanupLogsTimeoutFailureToStopChild` (L340) | `assertStringContainsString` | `assertLogged($logger, 'Process worker failed to stop forked child')` |
| `tests/Worker/GuzzleWorkerTest.php` | `testSendsAsyncRequestToLocalServer` (L~120) | `assertStringNotContainsString('Error while sending FCM', $loggedErrors)` | `assertNotLogged($logger, 'Error while sending FCM')` — a `RecordingLogger` is already installed at L160, so only the error-log block and the assertion line go |
| same | `testLogsConnectRefusedFailure` (L232) | `assertSame('', $loggedErrors)` | delete the assertion and the whole error-log block; pass a `RecordingLogger` instead of the `NullLogger` and add `assertNotLogged($logger, 'Error while sending FCM')` if the guard is worth keeping — see below |
| `.../PlatformEndpoint/PublishWorkerTest.php` | `testLogsOuterExceptionOnAckFailure` (L251) | `assertStringContainsString('SNS worker exception', ...)` | `assertLogged($logger, 'SNS worker exception')` |
| `.../PlatformEndpoint/RegisterWorkerTest.php` | `testLogsOuterExceptionOnAckFailure` (L231) | `assertStringContainsString('Register SNS worker exception', ...)` | `assertLogged($logger, 'Register SNS worker exception')` |
| `.../PlatformEndpoint/RemoveWorkerTest.php` | `testLogsOuterExceptionOnAckFailure` (L191) | `assertStringContainsString('Remove endpoints worker exception', ...)` | `assertLogged($logger, 'Remove endpoints worker exception')` |

### The two Guzzle tests

`src/Worker/Guzzle.php` has **zero** `error_log()` calls (verified: the only logging in the file is
`logDebug`, 9 call sites). `'Error while sending FCM'` appears nowhere in `src/`. So
`testLogsConnectRefusedFailure`'s `assertSame('', $loggedErrors, 'A refused connection must be
handled by the worker, not reported as a PHP error')` asserts an empty string against a file nothing
can write to — it cannot fail.

`testSendsAsyncRequestToLocalServer` already installs a `RecordingLogger` (L160), so its guard is a
one-line swap to `assertNotLogged($logger, 'Error while sending FCM')`. `testLogsConnectRefusedFailure`
passes `new NullLogger()`, so it needs a recorder installed before the negative assertion means
anything. That is worth doing rather than dropping the guard outright — these two tests exist to pin
the behaviour Plan 2 bought, and the surviving assertions (`assertContains(['afterWorkFailed', 16], …)`,
`assertStringContainsString('got response 200 ', $wholeLog)`) confirm it, but only the log assertion
says "and it did not leak as an error".

Do not reintroduce the error-log capture to keep the guard. If `Guzzle.php` ever grows a real
`error_log()`, the phpcs rule from item 6.5 fails the build — which is a stronger guard than reading
a temp file.

### Import cleanup in the 2 test files that need it

Only `AProcessWorkerTest.php` and `GuzzleWorkerTest.php` import the error-log helper functions. In
both, `file_exists`, `file_get_contents`, `ini_get`, `tempnam` and `unlink` appear exactly once per
error-log block (`ini_set` appears twice per block — set and restore): 7 blocks in
`AProcessWorkerTest`, 2 in `GuzzleWorkerTest`. After the rewrite all six drop to zero, so remove all
six `use function` imports from both files or phpcs fails on
`SlevomatCodingStandard.Namespaces.UnusedUses`.

`sys_get_temp_dir()` is used unimported in both files and passes phpcs today; it is inside the deleted
blocks, so nothing to remove.

**The three SNS test files need no import work at all.** They have no `use function` block whatsoever,
and each carries a class-level `@phpcs:disable` (e.g. `PublishWorkerTest.php:16-18`) that makes phpcs
skip them. The only import they need is the new one named under "Test-helper plumbing" below.

Keep `implode` and `array_column` in `AProcessWorkerTest` and `GuzzleWorkerTest`: both survive outside
the deleted blocks — `AProcessWorkerTest.php:104` and the `testLogsUnableToConnect` tests use them —
so their imports stay.

### Test-helper plumbing

- `AProcessWorkerTest::runWorker()` already takes `?LoggerInterface $logger = null` (line 371) and
  defaults to `new NullLogger()` — pass the recorder as the third argument, no change needed.
- The three SNS `makeWorker()` helpers hardcode `$worker->setLogger(new NullLogger());` (e.g.
  `PublishWorkerTest.php:43`). Widen to
  `private function makeWorker(?LoggerInterface $logger = null): Publish` with
  `$logger ??= new NullLogger();` as the first line, and add `use Psr\Log\LoggerInterface;`.

---

## 6.5 Stop it coming back: forbid `error_log` in phpcs

**Location:** `build/phpcs-ruleset.xml:248-254`. The `Generic.PHP.ForbiddenFunctions` rule is already
there with `extend="true"`. Add one element:

```xml
    <rule ref="Generic.PHP.ForbiddenFunctions">
        <properties>
            <property name="forbiddenFunctions" type="array" extend="true">
                <element key="sizeof" value="count"/>
                <element key="error_log" value=""/>
            </property>
        </properties>
    </rule>
```

An **empty** replacement means "forbidden, no suggested substitute" — that is what we want, since
the substitute varies (`logError` in a worker, a PSR-3 handler in user code).

**Verified in the `app-php83` container** by patching a copy of the ruleset and running
`phpcs --standard=/tmp/rs-probe.xml src/Worker/AProcess.php`: phpcs accepts the empty value and
reports exactly the 6 expected lines, including the `@`-silenced ones —

```
 75 | ERROR | The use of function error_log() is forbidden
181 | ERROR | The use of function error_log() is forbidden
188 | ERROR | The use of function error_log() is forbidden
218 | ERROR | The use of function error_log() is forbidden
259 | ERROR | The use of function error_log() is forbidden
261 | ERROR | The use of function error_log() is forbidden
```

The `@` silencer does not hide it, so the current code cannot slip past the rule.

Scope: `src/` and `tests/` only. `tests/LoggerTest.php` and `build/php.ini:30` still mention
`error_log`, and neither is a call site — the sniff matches calls, not names.

Do **not** add this to the pre-commit hook file list before the source change. The
`php-code-phpcbf` / `php-code-phpcs` hooks run per changed file, and a new hard error on a file the
implementer is mid-way through editing is a poor first experience. Land 6.1–6.4 and 6.6, then 6.5.

---

## 6.6 `build/php.ini` — decide, do not silently forget

`build/php.ini:30` sets `error_log=/dev/null`. That is what made the current tests possible: PHP
`error_log()` calls honour the INI destination, so the suite would otherwise write to stderr, and
the tests had to override the INI per test to get a readable file.

After 6.4 no test overrides it, and after 6.2/6.3 nothing in `src/` writes to it. The line is
therefore now dead configuration. Remove it. If it is kept, remove it in a follow-up with a comment
explaining why — an unexplained `error_log=/dev/null` in a library's dev ini is a trap for the next
person who adds a diagnostic.

Removing it does not change any test outcome: the only tests that read it are the 12 being rewritten,
and PHPUnit's own stderr noise is unaffected.

---

## 6.7 Recommended, separable: a PSR-3 file logger so the migration path exists

`src/Logger.php` is `BackQ\Logger` — a file appender with `log($sMessage, $debug = false)`. It does
**not** implement `Psr\Log\LoggerInterface`, and its method name and signature are incompatible with
`AbstractLogger::log($level, $message, array $context = [])`, so it cannot be made into one by
`extends`. Making it PSR-3 means renaming its public method: a 5.x break, in a plan that should not
need one.

The consequence of 6.2/6.3 alone: an operator whose process-worker failures currently land in the PHP
error log (`/var/log/php-fpm/…`, journald's `php-error` unit, a `php.ini` `error_log` file) will
stop seeing them there. By default they now go to the `ConsoleLogger` that `AbstractWorker::__construct()`
installs, which under supervisor or `docker logs` is captured anyway — so most operators lose
nothing. But a user who explicitly calls `setLogger(null)` to silence the worker currently *still*
gets these 9 messages in the PHP error log, and after this change gets silence.

The cheapest fix that keeps a zero-dependency file sink: a new `src/FileLogger.php` that extends
`Psr\Log\AbstractLogger` and reuses the same `fopen`/`fwrite`/`date` format, leaving
`BackQ\Logger` untouched for existing callers. `monolog/monolog` is not a dependency and this plan
should not add one.

**`log()` must keep `$message` untyped.** `composer.json` allows `psr/log` `^1.1 | ^2.0 | ^3.0`, and
`AbstractLogger::log()` differs across them — 3.x declares
`log($level, string|Stringable $message, array $context = []): void` while 1.x declares
`log($level, $message, array $context = array())` with no type and no return type. A shipped subclass
narrowing `$message` to `string|Stringable` is legal under 3.x and a **fatal**
`Declaration of ... must be compatible with ...` under 1.x — a break that only appears for the
consumer who happens to resolve the oldest allowed version, and that `php -l` will not catch. Declare
it wide and cast:

```php
final class FileLogger extends AbstractLogger
{
    /**
     * @param mixed                $level
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        // untyped $message on purpose: see the psr/log 1.x/3.x note above
    }
}
```

Untyped `$message` is contravariance and legal under all three majors; `: void` is legal too, because
a child may add a return type the parent does not declare. (Note `tests/Support/RecordingLogger.php:19`
already narrows — harmless today, since the suite only ever runs against the locked 3.x, but do not
copy that signature into shipped code.)

**Verified in the `app-php83` container** with four throwaway classes modelling the two base
signatures:

| child `log()` | 1.x-style base (untyped) | 3.x-style base (`string\|Stringable`) |
|---|---|---|
| untyped `$message`, `: void` | legal | legal |
| `string\|Stringable $message`, `: void` | **fatal**, exit 255 | legal |

The fatal exits silently — `build/php.ini` sets `display_errors=false` *and* `error_log=/dev/null`, so
the message goes nowhere. That is the exact class of failure `php build/check-classes.php` exists to
surface, and the reason `composer app-classes` is the first step of `app-code-quality`. Run it.

This is new public API, so it needs its own `UPGRADING` "New Features" entry. Ship it as a second
PR; the deprecation stands on its own without it.

---

## 6.8 `UPGRADING` and `AGENTS.md`

`UPGRADING` — under section 1 (Backward Incompatible Changes) of the 4.x → 5.x entry, because the
default sink for these 9 messages changes:

```
- `BackQ\Worker\AbstractWorker::logError()` and the process/SNS workers no longer write to the
  PHP error log. `AProcess` and the `Amazon\SNS\Application\PlatformEndpoint\{Publish,Register,
  Remove}` workers report payload, launch, fork-cleanup, timeout, signal and outer-loop failures
  through the PSR-3 logger set with `setLogger()` instead of `error_log()`. Code that watched
  `php.ini`'s `error_log` destination (journald, a web-server error log, `php-fpm`) for these
  messages must switch to a PSR-3 handler, or pass a file-writing logger to `setLogger()`.
  `logError()` is a no-op when no logger is set, so `setLogger(null)` now silences these messages
  completely — where `error_log()` used to keep reporting them.
- `BackQ\Worker\AbstractWorker::logError()` gained an optional
  `array $context = []` second parameter, forwarded to `Psr\Log\LoggerInterface::error()`. The
  former `@error_log()` call sites now pass the caught exception as `['exception' => $e]`.
  Subclasses overriding `logError(string $message)` are unaffected (the parameter is optional and
  nothing in the library overrides the method), but a subclass that declared a *narrower* signature
  must match.
```

If 6.7 ships, add under "2. New Features":
`- Added `BackQ\FileLogger`, a dependency-free `Psr\Log\LoggerInterface` implementation that appends
to a file, for workers that must keep writing to a log file.`

`AGENTS.md` — add a "Testing quirks" bullet so the pattern does not spread:

```
- Do not assert on `error_log()` output. `src/` reports worker failures through PSR-3
  (`AbstractWorker::logError()`); `build/phpcs-ruleset.xml` forbids the `error_log` function.
  Inject `BackQ\Tests\Support\RecordingLogger` via `setLogger()` and assert with the
  `assertLogged()` / `assertNotLogged()` helpers from `BackQ\Tests\Support\LogAssertions`.
```

And in the `plans/` bullet of the layout section, add plan 6 to the implemented list when it lands.

---

## Verification (run inside the `app-php83` container per AGENTS.md)

```bash
$script = @'
cd /app
php -l src/Worker/AbstractWorker.php
php -l src/Worker/AProcess.php
php -l src/Worker/Amazon/SNS/Application/PlatformEndpoint/Publish.php
php -l src/Worker/Amazon/SNS/Application/PlatformEndpoint/Register.php
php -l src/Worker/Amazon/SNS/Application/PlatformEndpoint/Remove.php
php -l tests/Support/LogAssertions.php
php -l tests/Worker/AProcessWorkerTest.php
php -l tests/Worker/GuzzleWorkerTest.php
php -l tests/Worker/Amazon/SNS/Application/PlatformEndpoint/PublishWorkerTest.php
php -l tests/Worker/Amazon/SNS/Application/PlatformEndpoint/RegisterWorkerTest.php
php -l tests/Worker/Amazon/SNS/Application/PlatformEndpoint/RemoveWorkerTest.php
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src tests --report=full
php -d memory_limit=-1 vendor/bin/phpcbf --standard=build/phpcs-ruleset.xml --no-cache src tests
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Worker/AProcess.php src/Worker/AbstractWorker.php
php ./vendor/bin/psalm.phar --config build/psalm.xml --no-diff --show-info=true src/Worker/AProcess.php src/Worker/AbstractWorker.php
grep -rn "error_log" src/ || echo "OK: no error_log left in src/"
grep -rn "ini_set..error_log" tests/ || echo "OK: no test reads the PHP error log"
php ./vendor/bin/phpunit --configuration=phpunit.xml
composer app-code-quality
'@
$script | docker exec -i app-php83 bash -s
```

Baseline before the change, so the diff in the test count is attributable: the 78 tests in
`AProcess|GuzzleWorker|PlatformEndpoint|LoggerTest` pass today (164 assertions, `OK`). The full suite
is green as well.

`src/Adapter/Beanstalk.php` and `src/Adapter/Beanstalk/Client.php` carry a class-level
`@phpcs:disable`; neither is touched here.

## Risks for the implementing agent

- **The `ini_set('error_log', ...)` blocks are not uniform.** One of them restores the INI value
  *before* reading the file (`AProcessWorkerTest.php:46-50` reads and unlinks inside the `finally`),
  the rest restore first and read after. Deleting them mechanically will leave an `$errorLog` or
  `$previous` variable referenced by a surviving assertion. Compile-check each test file with
  `php -l` and re-read the diff.
- **`assertNotLogged` must be given a `RecordingLogger`, not a `NullLogger`.** Three
  `AProcessWorkerTest` cases currently pass `NullLogger` *and* capture the error log; the negative
  assertions only work once the recorder is in place. Swapping the assertion without swapping the
  logger turns a negative assertion into a vacuous one — the exact bug this plan is fixing.
- **`LogAssertions` lands in `tests/`, which is in scope for both phpcs and psalm** (`psalm.xml`
  `extraFiles`). The `use function` block must be alphabetical, and the trait's `private` helper is
  fine under `findUnusedCode="false"`.
- **The `$context` addition is a public signature change** on `AbstractWorker`. Nothing overrides
  `logError()` today (verified), so no subclass breaks, but run `php build/check-classes.php` — the
  "Declaration of ... must be compatible with ..." fatal that `php -l` misses.
- **Do not "fix" `Guzzle.php`.** Its outer `catch` already uses `logDebug` (`Guzzle.php:133`),
  which is arguably the wrong level for a hard failure, but that is Plan 2's decision and out of
  scope here. Only the two dead test blocks are in scope.
- **`build/php.ini` is a container file.** If 6.6 removes `error_log=/dev/null` and the container is
  not rebuilt, the running `app-php83` still has the old INI and the sweep passes either way — the
  change is invisible until `docker compose up --build`. Verify by `docker exec app-php83 php -i | grep error_log`.
- **The inventory is a snapshot of the working tree, and `src/` is in flight.** Re-run the
  `grep -rn "error_log" src/` line in the verification block before starting, and treat any hit it
  finds as a new site. In particular `src/Worker/GuzzleForwarder.php` is currently a *staged but
  uncommitted* file that already uses `logError`-family logging and `trigger_error` — it has no
  `error_log()` today, but if it lands mid-plan the greps in the verification block are the source
  of truth, not this document.
