# Plan 4 — Minor notes & API behavior (findings 4.1 – 4.3)

> Status: **documented only** — no code changes made. Intended for implementation by another agent.
> Scope: section 4 of the analysis report (minor defects / contract mismatches).
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`.

## How to execute this plan

Same two-step ritual as Plans 1–3: **Phase A** (failing tests; RED on current code), then
**Phase B** (implementation; tests GREEN). Items 4.2 and 4.3 each have a red test + a one-line fix.
Item 4.1 and the closing notes are documentation-only (no red test, no source change) — record them
as known limitations; fix them opportunistically.

---

## 4.1 LOW — vendored Beanstalk `_decode()` emits PHP 8 string-offset warnings

**Location:** `vendor/davidpersson/beanstalk/src/Client.php`, `_decode()` (lines ~653–664):

```php
protected function _decode($data) {
    $data = array_slice(explode("\n", $data), 1);
    $result = [];

    foreach ($data as $key => $value) {
        if ($value[0] === '-') {
            ...
```

**Mechanism:** `$value[0]` on an **empty string** raises "Uninitialized string offset 0" under
PHP 8. Real beanstalkd stats responses end each YAML body line with a trailing `\n` (and the body with
a `\r\n`), so `explode("\n", $data)` produces a final empty element that flows straight into
`$value[0]`. The warning is noise only (`failOnWarning=false` in `phpunit.xml` makes the suite pass
anyway, and `displayDetailsOnTestsThatTriggerWarnings=true` surfaces it), but it pollutes logs.

Note why the repo's own test doesn't trip it: `FakeBeanstalkServer::queueStats()` deliberately writes
the YAML body **without** a trailing newline (see the method comment), so the final exploded element is
never empty in tests.

**Phase A — failing test (optional, only if the warning must be pinned):** no red test is strictly
required here (the suite does not fail on warnings). If one is desired, it must assert that calling
`stats()` against a fake server whose `queueStats` response ends with a **trailing newline** (mimicking
real beanstalkd) triggers **zero** captured warnings:

```php
// ClientTest.php
public function testStatsDecodeDoesNotWarnOnTrailingNewline(): void
{
    $client = $this->connectClient();

    // real beanstalkd ends each YAML line (and the body) with a trailing newline
    $body   = "---\r\ncurrent-workers: 1\r\nversion: 1.12\r\n";
    $this->server->queueResponse("OK " . strlen($body) . "\r\n" . $body);

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $stats = $client->statsTube('default');
        $this->assertIsArray($stats);
    } finally {
        restore_error_handler();
    }

    $this->assertSame([], $warnings);
}
```

Why RED today: the trailing `\n` leaves one empty `$value` → `$value[0]` triggers the warning → captured →
`assertSame([], $warnings)` fails. (`$body` must end with `\n` so `explode("\n", $data)` yields an
empty final element; build the `OK <len>` header from `strlen($body)` like `FakeBeanstalkServer::queueStats()`
does.)

**Phase B — fix (do not edit `vendor/`):** `src/Adapter/Beanstalk/Client.php` extends the vendored
`\Beanstalk\Client` — override `_decode()` there (or pre-trim incoming stats bodies in `_statsRead` /
`queueStats` consumers) and guard the empty element:

```php
// in BackQ\Adapter\Beanstalk\Client
protected function _decode($data): array
{
    $data = array_slice(explode("\n", $data), 1);
    $result = [];

    foreach ($data as $key => $value) {
        if ('' === $value) {
            continue;
        }
        if ($value[0] === '-') {
            ...
```

Keep behavior identical for non-empty lines (existing `testStatsRoundTrip` / `testStatsTubeRoundTrip`
assert the parsed shape and must stay green).

---

## 4.2 LOW — Beanstalk::pickTask() ignores its `$timeout` argument

**Location:** `src/Adapter/Beanstalk.php`, `pickTask()` (line 220):

```php
public function pickTask(?int $timeout = null): bool|array
{
    if ($this->connected) {
        try {
            $result = $this->client->reserve($this->workTimeout);   // <-- uses $this->workTimeout, ignores $timeout
```

The method's own docblock (lines 212) says: *"`$timeout` integer `$timeout` If given specifies number
of seconds to wait for a job, '0' returns immediately"* — but the argument is never used. Every other
adapter (`Nsq`, `Redis`, `DynamoSQS`) honors the parameter (`?int $timeout = null`), and
`AbstractWorker::work()` calls `pickTask($timeout)` where `$timeout` is the worker's derived wait
value. So a worker's time budgeting for Beanstalk is silently ignored.

**Phase A — failing test** (`tests/Adapter/BeanstalkAdapterTest.php`):

```php
public function testPickTaskForwardsTimeoutArgument(): void
{
    [$adapter, $client] = $this->adapterWithConnectedClient();
    $client->expects($this->once())->method('reserve')->with(7)->willReturn(false);

    $this->assertFalse($adapter->pickTask(7));
}
```

Why RED today: `pickTask(7)` → `reserve($this->workTimeout)` → `reserve(null)`, so the mock
expectation `with(7)` fails the test.

**Phase B — fix** (`src/Adapter/Beanstalk.php`, `pickTask()`):

```php
$result = $this->client->reserve($timeout ?? $this->workTimeout);
```

Ripple effects:
- `testPickTaskReturnsWrappedJob` (`->with(null)`, no arg + no `setWorkTimeout`) — unchanged.
- `testPickTaskRespectsWorkTimeout` (`->with(5)` after `setWorkTimeout(5)`, no arg) — unchanged
  (`null ?? 5 === 5`).
- The fix line overlaps Plan 3 item 3.3's `pickTask()` rethrow change; land both edits to `pickTask()`
  in one pass to avoid a rebase conflict.

---

## 4.3 LOW — AbstractPublisher::ready() returns `null` when not started

**Location:** `src/Publisher/AbstractPublisher.php`, `ready()` (lines 90–95):

```php
public function ready()
{
    if ($this->bind) {
        return $this->adapter->ping();
    }
}
```

**Mechanism:** when the publisher has not been started (`bind === null`), the method falls off the end
and returns `null`, violating the implicit boolean contract ("is the connection alive and ready to do
the job"). Callers doing `if ($publisher->ready())` treat `null` as falsy (works by accident), but any
strict `=== true` / strict-typed comparison breaks (e.g. `false === $publisher->ready()` fails).

**Phase A — failing test** (`tests/Publisher/AbstractPublisherTest.php`, `testReadyPingsOnlyWhenBound`,
line 82):

```php
$this->assertFalse($this->publisher->ready());   // was: assertNull(...)

$this->publisher->start();
$this->assertTrue($this->publisher->ready());    // unchanged
$this->assertContains(['ping', true], $this->adapter->calls);
```

Why RED today: not-yet-started `ready()` returns `null`, so `assertFalse(...)` fails.

**Phase B — fix** (`src/Publisher/AbstractPublisher.php`, `ready()`):

```php
public function ready(): bool
{
    if ($this->bind) {
        return $this->adapter->ping();
    }

    return false;
}
```

Ripple effects: none — the bound path is unchanged; add the `: bool` return type so static analysis
and callers see the contract.

---

## Closing notes (documentation only — no failing tests)

Record these in the repo notes / `AGENTS.md`-style comments as known limitations; address them only if
a related change makes them natural:

- **Stale comment in `StreamIO::__construct`:** the constructor still carries a
  "non-blocking" comment while every adapter (`Nsq`, `Beanstalk`) constructs it with
  `blocking=true`. Update the comment when touching `StreamIO` (see Plan 1, item 1.3).
- **Nsq logs full message bodies at INFO:** `Nsq::write()` logs `'--> writing ' . trim($buffer)` and
  `readFrame()` logs frame contents — queue payloads (potentially sensitive job data) end up in logs at
  the info level. Consider downgrading body logging to debug only.
- **DynamoSQS visibility quirk:** `afterWorkSuccess` returning `false` (Plan 3, item 3.6) makes
  `AbstractWorker::work()` throw `Worker failed to acknowledge job result` — that is the intended
  fail-loud behavior, but operators should know a delete failure now kills the worker (supervisor
  restart) instead of silently double-processing.

## Register: existing tests that assert buggy behavior (flip in Phase A / land with Phase B)

| Test file | Test | Current assertion | After fix |
|---|---|---|---|
| `tests/Publisher/AbstractPublisherTest.php` | `testReadyPingsOnlyWhenBound` | `assertNull($this->publisher->ready())` | `assertFalse($this->publisher->ready())` |
| `tests/Adapter/BeanstalkAdapterTest.php` | (new) `testPickTaskForwardsTimeoutArgument` | — | — |

## Verification (run inside the `backq.php83` container per AGENTS.md)

```bash
$script = @'
cd /app
php -l src/Adapter/Beanstalk.php
php -l src/Adapter/Beanstalk/Client.php
php -l src/Publisher/AbstractPublisher.php
php -l tests/Adapter/BeanstalkAdapterTest.php
php -l tests/Adapter/Beanstalk/ClientTest.php
php -l tests/Publisher/AbstractPublisherTest.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s tests/Adapter/BeanstalkAdapterTest.php tests/Adapter/Beanstalk/ClientTest.php tests/Publisher/AbstractPublisherTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Adapter/Beanstalk.php src/Adapter/Beanstalk/Client.php src/Publisher/AbstractPublisher.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter 'Beanstalk|Publisher'
php ./vendor/bin/phpunit --configuration=phpunit.xml
'@
$script | docker exec -i backq.php83 bash -s
```

Notes:
- `Beanstalk.php` and `Beanstalk/Client.php` carry class-level `@phpcs:disable` — phpcs skips them;
  verify with `php -l` + PHPStan + tests.
- 4.1's optional red test only matters if the warning must be pinned; the suite does not fail on
  warnings, so it is safe to defer.

## Risks for the implementing agent

- 4.2's fix is shared with Plan 3 item 3.3 (`pickTask()` rethrow). Apply both edits to
  `src/Adapter/Beanstalk.php` together.
- 4.3 adds a return type `: bool` to `ready()` — previously untyped. Confirm no caller relies on
  `null`; the existing test flip (assertNull → assertFalse) is the only affected assertion found.