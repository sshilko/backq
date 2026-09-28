# Plan 7 — Adapter architecture: one contract, one failure policy, one config object

> Status: **implemented** (phases 0–6, uncommitted on the working tree). The three defects in
> "The problem" were reproduced on 2026-09-27 against the working tree of this branch, in
> container `app-php83`; the structural inventory is measured from the same tree.
>
> **Amended while implementing.** Five things below did not survive contact with the code, and
> this body is the plan as written rather than as built. Read the amendments, not the items:
> 1. **Item 3.3 cannot land as written.** Both consumers still type `AbstractAdapter`, so
>    dropping `TestAdapter` as their base would break every test double. The four doubles keep
>    extending it, and 3.3's intent landed instead as two standalone-implementability tests in
>    `tests/Adapter/RoleInterfacesTest.php` (a class that implements `QueueConsumer` /
>    `QueueProducer` without extending anything). Narrowing the consumers is **deferred to 6.0**
>    and is recorded as such in `UPGRADING`.
> 2. **MySQL reconnect is a `\Closure` on `JobConfig`, not a new interface.** KISS: a factory
>    closure is the whole contract, and it needs no new type to be mocked in a test.
> 3. **The deprecations are docblock-only.** No `symfony/deprecation-contracts`, no
>    `trigger_deprecation()` — it is implemented as `trigger_error()`, which `AGENTS.md` forbids
>    by name. See "The Symfony question" below, option (a).
> 4. **Phase 5.1's line-count goal went the other way.** `src/Adapter/` grew rather than shrank;
>    the duplication metrics in the items are what fell. `AGENTS.md` now records the measured
>    `psalm-suppress` count, which **rose** from 10 to 12 rather than falling, because the new
>    `log()` / `report()` methods use the same `?->logger` call style.
> 5. **The plan's own `grep -rn trigger_error src/` verification cannot pass.** It was scoped
>    repo-wide, but `trigger_error()` remains in `AbstractPublisher::getInstance()`,
>    `AProcess` and `GuzzleForwarder` — pre-existing and out of scope, as the 4.x → 5.x
>    `UPGRADING` entry already states. Phase 0.4 removed the one in `src/Adapter/Redis.php`,
>    and `grep -rn trigger_error src/Adapter/` returns nothing.
>
> Scope: the 19 files and 2 804 lines under `src/Adapter/`, the 199 tests that cover them, and
> the 14 adapter call sites in `src/Worker/AbstractWorker.php` and `src/Publisher/AbstractPublisher.php`.
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`, `plan-4-minor-notes-behavior.md`,
> `plan-5-put-task-contract.md`, `plan-6-error-log-deprecation.md`.
>
> **Read this first:** Plans 1–3 already rewrote the beanstalkd transport and its resilience
> policy. This plan does not reopen any of their decisions and does not re-derive the protocol.
> It treats `src/Adapter/Beanstalk/Client.php` and `src/Adapter/IO/StreamIO.php` as *finished*
> work that is out of scope except where item 5.2 names a specific line.
>
> **Three decisions already taken elsewhere in this series, which this plan respects and does not
> reopen:**
> 1. The adapter logger is a **mandatory constructor argument**, and the call sites use
>    `$this?->logger->…` literally (38 in `Redis`, 13 in `MySql`, 11 in `Beanstalk`).
>    `build/phpcs-ruleset.xml` excludes `DisallowNullSafeObjectOperator` and six class-level
>    `@psalm-suppress` annotations exist for it. This plan therefore does **not** reintroduce
>    `logInfo()` / `logDebug()` / `logError()` helpers on `AbstractAdapter`.
> 2. `afterWorkSuccess()` / `afterWorkFailed()` take `?string $workId` (was `int|string|null`),
>    and `AbstractWorker` casts the id where it invokes them.
> 3. The `putTask()` contract is settled by Plan 5: one parameter, per-adapter **optional**
>    widening only, failures returned as `Throwable`.

## The problem

### The inventory, measured

| File | Lines | `logger->` sites | `try`/`catch` | What it is |
|---|---:|---:|---:|---|
| `AbstractAdapter.php` | 117 | 0 | 0 | the contract: 11 abstract methods |
| `Beanstalk.php` | 367 | 11 | 10 | beanstalkd adapter |
| `Beanstalk/Client.php` | 387 | 0 | 3 | in-repo delta over `davidpersson/beanstalk` |
| `PersistentBeanstalk.php` | 48 | 0 | 0 | `Beanstalk` + persistence |
| `MySql.php` | 376 | 13 | 5 | table-as-queue adapter |
| `Redis.php` | 659 | 38 | 3 | redis list adapter |
| `IO/StreamIO.php` | 474 | 0 | 1 | raw socket |
| `IO/AbstractIO.php` | 37 | 0 | 0 | 8 abstract stream methods |
| `Redis/{App,Manager,Queue,Connector}.php` | 168 | 0 | 0 | illuminate glue |
| `MySql/{JobConfig,JobState,JobColumn}.php` | 96 | 0 | 0 | value objects |
| `ConnectionState.php` | 25 | 0 | 0 | backed enum |
| **total** | **2 804** | **62** | **19** | |

The three test-support classes under `src/Adapter/MySql/` are what good looks like here: a
`final readonly` value object (`JobConfig`), two enums, no state, no I/O, fully covered by 16
fast unit tests. `MySql/JobConfig` is the precedent every other item in this plan follows.

### Cost 1 — three defects that make the library lie to its caller

These are not refactoring opportunities. Each one was reproduced; each one is a case where the
adapter reports success for work that did not happen.

**(a) `Redis` acknowledges a job it never reserved.** `Redis.php:342` and `Redis.php:378` are the
`return true` that every connected-and-bound call falls through to, including a call whose id was
never in `$reservedJobs`, and including `null`. Probe, with `connected = true`,
`state = BindRead`, `reservedJobs = []`:

```
afterWorkSuccess('never-reserved') = true
afterWorkFailed('never-reserved')  = true
afterWorkSuccess(null)            = true
```

The consequence is the one the worker depends on: `AbstractWorker.php:309` throws
`'Worker failed to acknowledge job result'` when the ack is false, and treats true as "the queue
knows this job is finished". A stale id, a `null` id, or a second ack therefore reports success and
leaves the real job sitting in the reserved set until `retry_after` reaps it. It is also
*internally inconsistent*: the same method throws `InvalidArgumentException` on an id **mismatch**
(`Redis.php:338`, `Redis.php:374`) and returns `true` on an id that is **absent**.

**(b) `MySql` acknowledges a row that does not exist.** `MySql.php:313` is
`return 0 <= $this->write($sql);` and `MySql.php:357` is
`return max(0, (int) $this->db->affected_rows);`. `max(0, …)` is never negative, so `0 <= …` is
always true and `afterWorkSuccess()` / `afterWorkFailed()` cannot fail. Probe: an `UPDATE … WHERE
id = "1"` against a table with no such row returned `true`. The `catch` at `MySql.php:314` is the
only path that can ever return false, and it only fires when the *server* rejects the statement.

**(c) `MySql::pickTask()` splices a publisher-chosen id into SQL unquoted and unescaped.**
`MySql.php:107` is

```php
'WHERE ' . $this->config->idColumn . ' = ' . $jobId;
```

`$jobId` is the `id` column of the row this method just read. `putTask()` *does* escape the id
before storing it (`MySql.php:165`, `:176`, `:188`), so a publisher can legitimately put a row
under an id that contains a quote — the column is a string column, and `putTask` accepts any
`int|string`. The pick path then concatenates that value raw. Probe, with the row id
`1' OR '1'='1`:

```sql
SELECT id, payload FROM backq_jobs WHERE sync = 'WAIT' LIMIT 1 FOR UPDATE
UPDATE backq_jobs SET sync = "LOCK", time_sync = "2026-09-27 21:20:33" WHERE id = 1' OR '1'='1
```

Every other statement in the class quotes and escapes the same value
(`MySql.php:165`, `:176`, `:188`, `:308`). One line was missed, and it is the line that runs on
**every pick**, from a value that came from the public `putTask()` API. This is a second-order SQL
injection: store once, then the worker's own lock statement carries the payload into the next
query. Fix it before anything else in this plan, in its own commit.

### Cost 2 — the failure policy is written 19 times, and it is different each time

There is no single answer to "what does this adapter do when the transport fails?". Each call site
decides for itself:

| Operation | `Beanstalk` | `Redis` | `MySql` |
|---|---|---|---|
| `connect()` | catches, logs, `false` | returns `true` without touching the network | `ping()` |
| `bindRead()` / `bindWrite()` | catches, logs, `false` | sets state, `_connect()`, `true` | `true` |
| `pickTask()` | catches, logs, **rethrows** | lets it propagate | catches, logs, rollback, `false` |
| `putTask()` | catches, logs, returns the `Throwable` | catches, logs, returns the `Throwable` | catches, logs, rollback, returns the `Throwable` |
| `afterWork*()` | catches, logs, `false` | **returns `true` on an unknown id** | **always `true`** (cost 1b) |
| `disconnect()` | catches, logs, `false` | catches, logs, `true` | `true` |

Nine of those cells in `Beanstalk` are the *same line of code*:

```php
$this?->logger->error(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
```

at `Beanstalk.php:181`, `:200`, `:220`, `:246`, `:287`, `:320`, `:340`, `:361` and `:88`. A
ninth variant routes through the `error()` bridge at `Beanstalk.php:105`. When the policy is
written nine times, a change to it is a nine-site change, and the next contributor will copy the
nearest one instead of the right one. That is what produced cost 1.

### Cost 3 — the contract forces six methods that no adapter can answer honestly

`AbstractAdapter` has 11 abstract methods. Measure what the three shipped adapters actually do
with the six that do not fit them:

| Method | `Beanstalk` | `Redis` | `MySql` |
|---|---|---|---|
| `hasWorkers(string $queue)` | real (`statsTube`) | logs `info` on every call, returns `false` | `return true;` (`MySql.php:255`) |
| `setWorkTimeout(?int)` | real | real, with a clamp | empty body (`MySql.php:261`) |
| `bindRead()` / `bindWrite()` | real | real | `return true;` (`:284`, `:290`) |
| `disconnect()` | real | real | `return true;` (`:278`) |
| `connect()` | real | sets a bool, connects later (`:594`) | `ping()` |

`MySql` answers five of the eleven with a constant or an empty body. `Redis::connect()` returns `true` without opening a
socket — the connection is built later, inside a private `_connect()` (`:606`) that `bindRead()` and
`bindWrite()` call, so a caller that connects and is told `true` has learned nothing. This is the
interface-segregation cost, paid daily: `AbstractPublisher::start()` (`AbstractPublisher.php:90`)
calls `connect()`, gets `true`, and believes it is connected to a queue it has not bound yet.

The reason it cannot be fixed in place is that `AbstractWorker` and `AbstractPublisher` each need
a *different* half of the contract, and the abstract class is the only thing they can name:

| Consumer | Methods it calls | Count |
|---|---|---:|
| `AbstractWorker` | `setWorkTimeout`, `connect`, `bindRead`, `pickTask`, `afterWorkSuccess`, `afterWorkFailed`, `disconnect` | 7 |
| `AbstractPublisher` | `connect`, `bindWrite`, `putTask`, `hasWorkers`, `ping`, `disconnect` | 6 |

The union is the whole abstract class, and the overlap is only `connect` / `disconnect`.

### Cost 4 — the test suite pays for it too

`tests/Support/` carries **five** adapter doubles, and every one of them exists because a double
cannot implement a subset of the contract:

| Double | Overrides | Methods it actually needs |
|---|---|---:|
| `TestAdapter` | the full 11 | 11 |
| `ThrowingPickAdapter` | `pickTask` | 1 |
| `SleepingPickAdapter` | `pickTask` | 1 |
| `ThrowingPutTaskAdapter` | `putTask` | 1 |
| `FailingPutTaskAdapter` | `putTask` | 1 |
| `TestMySqlAdapter` | nothing at all — only makes `MySql` instantiable | 0 |

`TestMySqlAdapter` is the tell: `MySql` is declared `abstract` (`MySql.php:69`) and implements
every one of the 11 abstract methods, so there is nothing to extend — the class is abstract for no
reason, and a one-line test shim exists to work around it. (MySql is the only file in `src/` with
`declare(strict_types=1)`.)

### Cost 5 — 45% of the directory is invisible to the linter

Four files in `src/Adapter/` carry a class-level `@phpcs:disable`, so phpcs skips them entirely:

| File | Lines unchecked |
|---|---:|
| `IO/StreamIO.php` | 474 |
| `Beanstalk/Client.php` | 387 |
| `Beanstalk.php` | 367 |
| `PersistentBeanstalk.php` | 48 |
| **total** | **1 276 of 2 804 = 45.5%** |

Six properties in the directory are untyped — `Redis.php:62` (`$connected`), `Redis.php:110`
(`$retryAfter`), `Beanstalk.php:43` (`$connected`), `IO/StreamIO.php:75` (`$sock`),
`Redis/Queue.php:30` and `:35` (redeclared over the illuminate parent) — and psalm reports each
one as `MissingPropertyType`. Commented-out code sits at `Beanstalk.php:79`, `Beanstalk.php:120`,
`Redis.php:610` and `Beanstalk/Client.php:215`. `Redis.php:31` imports `trigger_error` and
`Redis.php:145` calls it, which **AGENTS.md forbids outright** ("`trigger_error()` MUST NEVER be
used") — and it is the worst of the three defects to leave, because an illuminate exception
anywhere in the Redis adapter becomes an `E_USER_WARNING` that a `set_error_handler` in user code
converts into a throwable, somewhere far from the cause.

### Cost 6 — configuration is a parameter list, not an object

`Redis::__construct()` takes 9 settings as positional/named parameters and carries three
suppressions to survive the linters (`Redis.php:119-121`:
`PHPMD.ExcessiveParameterList`, `TooManyArguments`, `PhanParamTooMany`). `Beanstalk::connect()`
takes 5, one of them an **untyped** `$logger = null` (`Beanstalk.php:64`) that only exists because
the adapter used to *be* the client's logger. `Beanstalk/Client.php:41-45` then rebuilds an
associative array with a `'logger'` key — and the vendored `_error()` at
`vendor/davidpersson/beanstalk/src/Client.php:161` only calls `$this->_config['logger']->error()`,
which **any PSR-3 logger already satisfies**. The whole `Beanstalk::error()` bridge
(`Beanstalk.php:105`) and the `($logger ?: $this)` hand-off at `Beanstalk.php:76` exist to bridge a
gap that PSR-3 closed years ago. No test calls `error()`.

The same constructor also creates an `Illuminate\Container\Container` (`Redis.php:137`) and binds
an anonymous `ExceptionHandler` (`:139-167`) whose only job is the `trigger_error` above.

## The design

Seven rules, in the order they win when two of them disagree. "Resilient" and "less error-prone"
outrank "simpler", and "simpler" outranks "flexible", because flexibility that costs a reader more
than it saves the next contributor is not flexibility.

1. **One failure policy, in one place, stated as a table.** Every adapter operation resolves a
   transport or storage failure the same way, and the table below is the whole specification. A
   second policy is a bug, not a variation.
2. **Report what happened, not what was attempted.** An ack returns `true` only if the queue was
   told. "The statement ran and matched nothing" is not an ack. This is cost 1a and 1b, and it is
   the rule that makes the three fixed adapters agree.
3. **Values in, effects out.** Configuration is a `final readonly` value object per adapter, the
   shape `MySql\JobConfig` already has. No adapter stores a connection setting in a property that
   a caller can also reach through a setter.
4. **The existing contract is not renamed, moved, or reshaped.** `AbstractAdapter` stays, its 10
   method names stay, `putTask()`'s variance rule stays. New names are added beside it, and the
   old ones keep working. Every phase below is a minor or a deprecation, never a removal.
5. **An interface earns its place by having two implementations or a subset consumer.** The test is
   in "The interfaces, and the test each has to pass". Most candidates in this plan fail it; the two
   that pass are in phase 3.
6. **No new runtime dependency, and no Symfony component where a line of our own is clearer.**
   The dependency table records the decision per candidate, including the four that were rejected.
7. **A defect fix ships alone.** Phase 0 is four commits that touch no signature, so a bisect can
   isolate them and a revert is a revert of a bug, not of a refactor.

### The failure policy (rule 1, written out)

Every cell is testable, and phase 1 adds the test that names it.

| Operation | Transport / storage failure | Not connected or not bound | Success |
|---|---|---|---|
| `connect()` | log `error`, `false` | — | `true` **only after the transport answered** |
| `bindRead()` / `bindWrite()` | log `error`, `false` | — | `true` when bound |
| `pickTask()` | log `error`, **rethrow** (cost 2: `MySql` returns `false` today, which the worker reads as "idle"; that is the one cell that stays as it is, see below) | `false` | the job array |
| `putTask()` | log `error`, return the `Throwable` | return a `RuntimeException` | the id |
| `afterWorkSuccess()` / `afterWorkFailed()` | log `error`, `false` | `false` | `true` **only if the queue was told** |
| `disconnect()` | log `error`, `false` | `false` | `true` when closed |
| `ping()` | log `error`, `false` | `false` | `true` when alive |
| `hasWorkers()` | log `error`, `false` | `false` | the answer |

`pickTask()` is the deliberate exception. `MySql::pickTask()` returning `false` on a *query*
failure is indistinguishable from an empty queue, and the worker treats `false` as an idle cycle
(`AbstractWorker.php:311`, whose own docblock says the quiet
path is a heartbeat / idle poll). Fixing that is a behaviour change to the worker's error handling, and
Plan 2's text left it open, so this plan does not touch it. It is recorded in "Out of scope" and
carries the one open question the maintainer has to answer (see the last risk).

### The interfaces, and the test each has to pass

Rule 5 in practice. A new interface is proposed only if it has **two independent implementations**
or **a consumer that needs a strict subset**. Everything else is a class or a value object.

| Candidate | Implementations today | Consumer | Verdict |
|---|---|---|---|
| `QueueConsumer` — `bindRead`, `pickTask`, `afterWorkSuccess`, `afterWorkFailed` | 3 | `AbstractWorker` (1) | **propose** — see below |
| `QueueProducer` — `bindWrite`, `putTask`, `hasWorkers`, `ping` | 3 | `AbstractPublisher` (1) | **propose** — see below |
| `ConnectionAware` — `connect`, `disconnect` | 3 | both | fold into the two above; a third interface for 2 methods with 2 consumers is ceremony |
| `Acknowledger` — the two acks | 3 | `AbstractWorker` (1) | **reject** — subset of `QueueConsumer` with no second consumer |
| `HasWorkersAware` | 2 (one of them a lie) | `AbstractPublisher` (1) | **reject** — it would exist to name a lie, and rule 2 says fix the lie instead |
| `Loggable` | 3 | — | **reject** — the logger is a constructor argument, not a contract |
| `Adapter` (replaces the abstract) | 3 | both | **reject** — it is `AbstractAdapter` renamed, for no gain |

The two that pass, and the reason: the worker and the publisher are **separate packages of
behaviour** with a 1-method overlap, and there is a second consumer each that does not exist yet
but is cheap to create and is already needed by the test suite. `tests/Support/FailingPutTaskAdapter`
overrides one method out of eleven; with `QueueProducer` it can implement four. That is the payoff,
and it is why phase 3 is in this plan at all.

## Out of scope (considered and deliberately rejected)

- **A DI container for adapters.** `symfony/dependency-injection` is installed (it arrives with
  `illuminate`), and it is the wrong tool: the adapters are constructed by the *user*, in their own
  composition root, with two arguments. A container here adds a config file to avoid a `new`.
- **PSR-11 / PSR-14 / PSR-15 for the adapters.** No cache pool, no event dispatcher, no HTTP
  factory. The adapters are not those things' clients; adopting the interfaces would mean
  implementing them for an audience that does not exist.
- **Replacing `illuminate/queue` in the Redis adapter.** It is 168 lines of glue
  (`Redis/{App,Manager,Queue,Connector}.php`) around a battle-tested payload format and a
  `retry_after`/`migrate` implementation. Writing our own list queue would be a different, much
  larger project with a much worse risk profile. Phase 4 shrinks what we own; it does not replace
  what we depend on.
- **Rewriting the beanstalkd transport.** Plans 1–3 own `Beanstalk/Client.php` and
  `IO/StreamIO.php`. Item 5.2 names the four lines this plan wants changed; the rest is theirs.
- **`putTask()`'s contract.** Plan 5, settled. Not reopened, including the `$jobId` asymmetry
  (`putTask()` takes `int|string|null`, the acks take `?string`).
- **`pickTask()` on failure.** See the policy table; the open question is at the end.
- **The `MySql::putTask()` `SELECT … FOR UPDATE` + `UPDATE`-or-`INSERT` upsert.** It is correct and
  tested. Rewriting it as `INSERT … ON DUPLICATE KEY UPDATE` is a real simplification and a real
  risk (it changes the locking window, and `putTask` currently holds `FOR UPDATE` across the
  write). Record it, do it in its own plan with its own concurrency test.

## How to execute this plan

Seven phases, each independently shippable, ordered so that the value lands before the cost:
**Phase 0** is three bugs and one rule violation, with no signature change. **Phase 1** pays for
itself in deleted lines. **Phases 2 and 3** are the API additions. **Phase 4** is resilience.
**Phase 5** puts the linter back on. **Phase 6** is the documentation.

Do not start phase 3 before phase 1 has landed, and do not start phase 4 before phase 2: the
config object is what makes the connect/state work in 4.1 and 4.2 cheap.

---

## Phase 0 — correctness, no signature change (four separate commits)

### 0.1 `MySql::pickTask()` quotes and escapes the id (cost 1c, security)

**Location:** `src/Adapter/MySql.php:104-109`

```php
$sql = 'UPDATE ' . $this->config->table . ' ' .
    'SET ' . JobColumn::State->value . ' = "' . JobState::Lock->value . '", ' .
    JobColumn::Time->value . ' = "' . date('Y-m-d H:i:s') . '" ' .
    'WHERE ' . $this->config->idColumn . ' = ' . $jobId;
```

becomes `'= "' . $this->escape((string) $jobId) . '"'`, following `MySql.php:308`, which escapes
and quotes the same value in the same statement shape. One line, and the three other statements
are the model. Note that they are not consistent among themselves about quote style — `:165` uses
`'`, `:176` and `:308` use `"` — so do not "tidy" that while fixing this; it is a separate,
test-visible change, and every one of those four sites has a test asserting the exact string
(`MySqlAdapterTest.php:225`, `:244`, `:312`, `:321`).

**Phase A — the test is RED first.** Add to `tests/Adapter/MySqlAdapterTest.php`, next to
`testPickTaskLocksTheJobItTook`, a test that feeds the row an id carrying a quote and pins the
statement:

```php
public function testPickTaskQuotesTheJobIdItLocks(): void
{
    $db      = $this->db([['id' => "1' OR '1'='1", 'payload' => 'serialized']]);
    $adapter = $this->adapter($db);

    $adapter->pickTask();

    $this->assertStringEndsWith('WHERE id = "1\' OR \'1\'=\'1"', $this->statements[1]);
}
```

The existing `testPickTaskLocksTheJobItTook` (`:101-119`, the pin itself at `:118`) pins the *unquoted* form
(`'WHERE id = 7$#'`) and must be updated to the quoted form in the same commit. Both tests are
worth keeping: one pins the shape, the other pins the escaping.

Note the fake's `real_escape_string` in `MySqlAdapterTest::db()` (`:410-414`) is a hand-rolled
`str_replace("'", "\\'", …)`, so the expected string in the new test has to be written against
*that* escaping, not against a real server's. The assertion above does that.

### 0.2 `MySql::updateState()` stops reporting success it cannot see (cost 1b)

**Location:** `src/Adapter/MySql.php:313` and `:357`

`return 0 <= $this->write($sql);` is vacuous. The fix is **not** `0 < $this->write($sql)`: an
`UPDATE` that writes the value a row already holds also reports 0 affected rows, so `0 <` would
report a failure for a job that is already `DONE`, and a re-delivered id would start throwing
inside the worker. The honest reading of this cell is "the statement executed", which is what the
code already does — so the fix is to make that explicit and to surface the case that is worth
knowing about:

```php
$affected = $this->write($sql);
if (0 === $affected) {
    $this?->logger->debug(__FUNCTION__ . ': no row matched ' . $workId);
}

return true;
```

with the existing `catch (mysqli_sql_exception)` still returning `false` — that is the one real
failure mode, and it is the one the worker acts on. `write()` keeps `max(0, …)`; its `@return`
docblock should say "0 when the statement matched no row, or was not a write" so the next reader
does not re-derive the trap.

**Test:** a new case in `MySqlAdapterTest` that acks an id the fake does not match and asserts
`true` **plus** a `debug` record naming the id — so if someone later changes the return to depend
on the row count, the test says which behaviour was chosen. `RecordingLogger` is the logger; a
`NullLogger` makes the second half vacuous (AGENTS.md).

### 0.3 `Redis` acks only a job it reserved (cost 1a)

**Location:** `src/Adapter/Redis.php:315-346` and `:353-382`

Both acks fall through to `return true` when the id is absent from `$reservedJobs`. The fix is to
make the three outcomes distinct, and they are already three distinct things in the code:

| Case | Today | After |
|---|---|---|
| connected, bound, id in `reservedJobs`, ids match | acts, `true` | unchanged |
| connected, bound, id in `reservedJobs`, ids differ | throws `InvalidArgumentException` | unchanged |
| connected, bound, **id absent** | **`true`** | `false` + `debug` |
| connected, bound, **`null` id** | **`true`** | `false` + `debug` |
| not connected / not bound | `false` | unchanged |

`false` is right and not `throw`: the worker's contract is that a failed ack re-opens the job
(`AbstractWorker.php:309`), and a throw would kill the worker over a bookkeeping miss. A debug
record — not `error` — because an unknown id is a lost id, not a transport failure.

**Phase A:** three RED tests in `RedisAdapterCoreTest`, using the existing `setState()` /
`setReservedJobs()` reflection helpers the file already defines (`:392` and `:387`):

```php
public function testAfterWorkSuccessRejectsAnUnreservedJobId(): void
public function testAfterWorkFailedRejectsAnUnreservedJobId(): void
public function testAnAckRejectsAMissingJobId(): void   // afterWorkSuccess(null)
```

All three assert `false`, not an exception. Then the implementation.

### 0.4 `trigger_error()` leaves the Redis adapter (cost 5, an AGENTS.md violation)

**Location:** `src/Adapter/Redis.php:31`, `:139-167`

The anonymous `ExceptionHandler` in the constructor turns **any** illuminate exception into
`trigger_error($e->getMessage(), E_USER_WARNING)`. AGENTS.md forbids `trigger_error()` in `src/`,
`tests/` and `example/`, and the construct is a hazard regardless: a user `set_error_handler`
turns the warning into a throwable at an arbitrary point inside a queue operation.

The fix is smaller than it looks — the closure already sits in a method that has `$this`:

```php
$reportable = function (Throwable $e) use ($logger): void {
    $logger->error($e->getMessage(), ['exception' => $e]);
};

$this->app->bind('exception.handler', static fn () => new class ($reportable) implements ExceptionHandler { … });
```

`report()` calls `$this->report($e)`; the `use function trigger_error` import and both
`E_USER_WARNING` / `E_USER_WARNING`'s import go. `renderForConsole()` and `shouldReport()` keep
their bodies.

**Behaviour change, so it needs an `UPGRADING` entry:** a consumer who installed an error handler
to observe queue failures will no longer see `E_USER_WARNING`. That is the point — PSR-3 is the
channel, per Plan 6 — but it is a change.

The `Symfony\Component\HttpFoundation\Response` import at `Redis.php:26` is still needed by
`render()`'s return type, so it stays.

### 0.5 What Phase 0 does **not** touch

`Beanstalk::pickTask()`'s rethrow (`Beanstalk.php:245-249`) is the one place where a failure
propagates, and it is right: Plan 2 made transport failures propagate so the worker can decide.
The policy table above keeps it. Consistency is reached by making the *other* adapters match the
documented intent, not by flattening everything to `false`.

---

## Phase 1 — one failure policy (deletes lines, adds none)

### 1.1 A shared, private attempt helper

**Location:** new `protected function attempt(string $operation, Closure $operation_body): bool`
on `AbstractAdapter`, documented with the policy table as its contract.

It owns the three things every cell repeats: the connected/bound precondition, the
`catch (Throwable $e)`, and the log line. Its body is the adapter-specific part:

```php
if ($this->connected) {
    if ($this->client->delete((int) $workId)) {
        return true;
    }
}

return false;
```

becomes, in `Beanstalk::afterWorkSuccess()` (`:332-345`):

```php
return $this->attempt(__FUNCTION__, fn (): bool => $this->client->delete((int) $workId));
```

Nine call sites in `Beanstalk` collapse from ~10 lines each to one; the five stub bodies in
`MySql` and the guards in `Redis` go the same way. The helper takes a `Closure` and not a
`callable` string, so the adapter's own types stay visible to psalm and phpstan inside the
closure — which is the whole reason not to reach for a magic `$this->call('delete', …)` proxy
(`Beanstalk::Client` has `_write` / `_read` / `_error` for exactly that, and it costs more in
indirection than it saves).

Two hooks, both `protected`, so no adapter has to repeat a policy cell:

- `protected function preconditionFailed(string $operation): void` — the `debug` line for
  "not connected" / "not bound". Default: nothing. `MySql` overrides it to note that its
  connection is caller-owned.
- `protected function isReady(): bool` — the guard itself, `return true;` in `AbstractAdapter`
  (for `MySql`, where there is nothing to check), overridden in `Beanstalk` and `Redis`.

**Rules for the implementing agent:** the helper logs and returns `false`; it **never** rethrows.
The one operation that rethrows is `pickTask()`, and it gets a sibling
`protected function attemptRethrowing(string $operation, Closure $operation_body): mixed` rather
than a `$rethrow` boolean flag — a flag would put the policy back in the call site, which is the
thing being removed.

This does not contradict decision 1 at the top of this plan: the helpers are `attempt` /
`isReady` / `preconditionFailed`, not `logError()` / `logDebug()`. The 62 `$this?->logger->…` call
sites stay; what disappears is the 19 try/catch blocks and the nine copies of the log line.

### 1.2 One log line, with the exception object in the context

The line being de-duplicated already discards the exception:

```php
$this?->logger->error(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());
```

`$e->getMessage()` is the only thing that survives, so the class, the stack and the previous
exception are gone by the time a human reads it. The helper takes the opportunity:

```php
$this?->logger->error(
    self::class . ' adapter ' . $operation . ' exception: ' . $e->getMessage(),
    ['exception' => $e]
);
```

PSR-3 `context` is what Plan 6 standardised on, `AbstractWorker::logError()` already takes it, and
this is the adapter-side equivalent. **Test impact:** `BeanstalkAdapterTest` and
`MySqlAdapterTest` assert on log text via their `messages()` helper; those assertions keep working
because the message is unchanged. Assert on `['exception']` being present in **one** new test per
adapter so the context is not decorative.

### 1.3 The `error()` bridge and the legacy `$logger` parameter go

**Location:** `src/Adapter/Beanstalk.php:64` (`$logger = null`), `:76`
(`'logger' => ($logger ?: $this)`), `:105-108` (`error()`), and
`src/Adapter/PersistentBeanstalk.php:32` and `:36` (the signature and the
`($logger ? $logger : $this)` hand-off)

The vendored client only needs something with an `error(string)` method
(`vendor/davidpersson/beanstalk/src/Client.php:161-165`), and `LoggerInterface` has one. So:

- `connect()` drops its fifth parameter. Removing an **optional** parameter from a public method is
  backwards compatible for callers; it is a documented BC note in `UPGRADING` for anyone who
  passed a logger there in 4.x.
- the config array gets `'logger' => $this->logger`.
- `Beanstalk::error()` is deleted, and its two internal callers (`:88`, `:150`) become
  `$this?->logger->error(…)` like the other seven.
- `PersistentBeanstalk::connect()` loses the parameter too.

No test calls `error()` (verified), so nothing in the suite needs rewriting; the
`BeanstalkAdapterTest` "error path logs and returns false" case goes through the client mock
already.

**Careful:** `Beanstalk/Client.php:41-45`'s `$defaults` array key order is asserted by
`BeanstalkAdapterTest` (host, logger, persistent, port, timeout — per AGENTS.md). Do not reorder
it, and note that both `Beanstalk.php` and `Beanstalk/Client.php` are `@phpcs:disable`, so
`php -l` plus the tests are the only gates for this item (item 5.1 changes that).

---

## Phase 2 — configuration becomes a value object

The precedent is `MySql\JobConfig` (39 lines, `final readonly`, 7 tests). Apply it twice.

### 2.1 `Redis\RedisConfig`

**Location:** new `src/Adapter/Redis/RedisConfig.php`, replacing the 9 constructor parameters at
`src/Adapter/Redis.php:123-134`.

`final readonly class RedisConfig` with the same 9 settings the constructor takes today (host,
port, persistent, persistent_id, prefix, timeout, read_timeout, database_id, auth_password),
validated in the constructor: `port` in 1…65535, `timeout` and `read_timeout` ≥ 1, `database_id`
≥ 0. Three suppressions disappear with the parameter list
(`Redis.php:119-121`). The adapter takes `(LoggerInterface $logger, RedisConfig $config = new
RedisConfig())` — named arguments keep every existing call working:
`new Redis($logger, host: 'x', port: 6380)` becomes
`new Redis($logger, new RedisConfig(host: 'x', port: 6380))`, which **is** a BC break and gets
its own `UPGRADING` entry with a before/after table, the same treatment Plan 5 gave
`putTask()`. If the maintainer wants 5.x to stay source-compatible, ship `RedisConfig` as
*additive* in 5.x — the constructor keeps its parameters and builds the object — and switch the
signature in 6.0. **That decision is the maintainer's; the plan supports either.**

Validate rather than reject silently: today `port: 0` or `read_timeout: 0` reaches
`stream_socket_client()` / the illuminate connector and fails somewhere deeper with a message that
does not name the setting. A value object whose constructor throws `InvalidArgumentException`
naming the field is the "less error-prone" win, and it is testable without a Redis server — which
is why it belongs before phase 4.

### 2.2 `Beanstalk\Connection`

**Location:** new `src/Adapter/Beanstalk/Connection.php`, replacing the `$bconfig` array at
`src/Adapter/Beanstalk.php:71-77` and the 5 parameters of `connect()`.

Same shape: host, port, timeout, persistent, plus the stream context that
`IO/StreamIO::__construct()` already takes (`StreamIO.php:86-94`) and that no adapter currently
exposes — an in-repo client that supports TLS whose adapter cannot ask for it. `connect()` becomes
`connect(Connection $connection = new Connection())`. As with 2.1, decide additive-now or
breaking-now before starting.

`PersistentBeanstalk` then holds a `bool $persistentConnection` and passes a `Connection` with
`persistent: true` — no `connect()` override, no `else` in `disconnect()`
(`PersistentBeanstalk.php:27-45` today).

### 2.3 The MySQL clock moves into the statement

**Location:** `src/Adapter/MySql.php:106`, `:174`, `:190` — `date('Y-m-d H:i:s')` in three
statements.

The column is a `timestamp` (`MySql.php:42`), and the value should come from the clock that owns
it: replace the PHP string with `NOW()` in the SQL. Three concrete wins, no dependency: the
`time_sync` value stops depending on the worker's PHP timezone versus the server's (they are
frequently different in a container, and this is the kind of bug that only shows up in a DST
week); `date()` disappears from the file; and the three statements lose a `sprintf`-worthy
interpolation. The tests assert the shape with a `\d{4}-\d{2}-\d{2} …` regex
(`MySqlAdapterTest.php:116-118`), so the regex becomes `NOW\(\)` — a **test change that makes the
intent explicit**, which is the point.

`symfony/clock` (PSR-20 `ClockInterface`, with `MockClock`) is the alternative and it is **not
recommended here**: it would make the PHP clock injectable, but the timestamp is the *database's*
to write. Injecting a clock into the adapter to format a value the server should produce is a
dependency in search of a problem. The one place a clock *would* earn its keep is
`AbstractWorker`'s `time()` calls and `StreamIO`'s `usleep()` — out of scope here, recorded in the
dependency table.

---

## Phase 3 — the two role interfaces, and the doubles that stop lying

### 3.1 Add the interfaces; do not touch the abstract class

**Location:** new `src/Adapter/QueueConsumer.php` and `src/Adapter/QueueProducer.php`;
`src/Adapter/AbstractAdapter.php` gains `implements QueueConsumer, QueueProducer` and **keeps all
11 abstract methods exactly as they are**.

That is the whole trick: the interfaces are additive, the abstract class remains the one thing
`AbstractWorker` and `AbstractPublisher` name (rule 4), and a user who wants a small adapter can
implement `QueueProducer` — 4 methods — instead of extending the abstract.

```php
interface QueueConsumer
{
    public function bindRead(string $queue): bool;
    public function pickTask(?int $timeout = null): bool|array;
    public function afterWorkSuccess(?string $workId): bool;
    public function afterWorkFailed(?string $workId): bool;
}

interface QueueProducer
{
    public function bindWrite(string $queue): bool;
    public function putTask(string|Stringable $body): null|string|Throwable;
    public function hasWorkers(string $queue): bool;
    public function ping(bool $reconnect = true): bool;
}
```

`connect()` and `disconnect()` are deliberately **not** in either interface: both consumers call
them, so a role interface that omitted them would not be usable on its own, and a third interface
for two methods with two consumers fails rule 5. The cost is that a standalone `QueueProducer`
cannot be connected — the docblock says so, and a user who needs that implements the two methods
too.

### 3.2 The two consumers name what they need

**Location:** `src/Worker/AbstractWorker.php:72`, `src/Publisher/AbstractPublisher.php:39,41`

```php
public function __construct(private AbstractAdapter $adapter, ?int $workTimeout = self::DEFAULT_WORK_TIMEOUT)
```

stays as it is **in 5.x**. The type only narrows to `QueueConsumer` in 6.0, when
`AbstractAdapter` is free to stop being the parameter type. Changing the parameter type in 5.x
would reject any third-party adapter that does not implement the new interface, which rule 4
forbids. So phase 3.2 is: add the interfaces (3.1) now, and record the narrowing as a 6.0 item
with the deprecation ladder in `UPGRADING`.

What *does* land in 5.x is a compile-time check that the two consumers only call methods their role
interface declares — which, once 6.0 arrives, phpstan and psalm can enforce with no further work.

### 3.3 The doubles implement the subset they exercise

**Location:** `tests/Support/ThrowingPickAdapter.php`, `SleepingPickAdapter.php`,
`ThrowingPutTaskAdapter.php`, `FailingPutTaskAdapter.php`, `TestMySqlAdapter.php`

- `ThrowingPickAdapter` and `SleepingPickAdapter` become `implements QueueConsumer` — 4 methods
  instead of 11, and they stop extending `TestAdapter`, which means a worker test can no longer
  accidentally rely on `putTask` behaviour those tests never set up.
- `ThrowingPutTaskAdapter` and `FailingPutTaskAdapter` become `implements QueueProducer`.
- `TestAdapter` keeps extending the abstract class: it is the one double both roles need, and
  `AbstractWorkerTest` asserts on its `calls` log, which is worth keeping as the recording
  double.
- `TestMySqlAdapter` (`tests/Support/TestMySqlAdapter.php:13`) is deleted outright, because item
  4.3 makes `MySql` instantiable. That is the plan deleting a test-support class, which is the
  measure of cost 4.

Every one of these is a test-only change; the suite is the verification. Expect the
`[$call, $args]` shape of the doubles' own recording to be the fiddly part — keep each double
recording the same calls it records today so the 199 existing assertions do not move.

---

## Phase 4 — resilience and one state instead of two

### 4.1 `Redis::connect()` actually connects

**Location:** `src/Adapter/Redis.php:594-604` and the private `_connect()` at `:606`

`connect()` sets `$this->connected = true` and returns. The first network call happens on the
first `bindRead()` / `bindWrite()`, and if it throws, the caller learns about it from
`bindRead()` returning `false` — after `connect()` already said yes.

Split the two responsibilities, which are currently in one method with the wrong name:

- `connect()` builds the connection and verifies it (`ping()`), so `true` means "there is a
  socket". It needs the queue name, which today only `bindRead()` / `bindWrite()` supply — so the
  name moves into `RedisConfig` (item 2.1) and `bindRead()` / `bindWrite()` only record the role.
  That is the dependency this phase has on phase 2, and the reason the plan sequences them so.
- `private function ensureConnected(): void` keeps the lazy build for the case where a caller
  binds first, and both paths converge on one private method, so there is one construction site
  instead of two.

`_connect()` loses its leading underscore while it is being moved (rule: a private method does not
need the Hungarian prefix, and the file has no other one), and the commented-out `encrypter`
binding at `:610-612` is deleted rather than commented.

**Resilience that comes with it:** `connect()` currently *disconnects first* if already connected
(`:598-600`), which throws away a live connection and its reserved jobs. Report that as a
decision to make, not a bug to fix silently: reconnect-on-`connect()` is defensible, but it must
not silently release reserved jobs. The tests to write are "connect twice keeps the reserved set"
and "bind after a failed connect reports false, not an exception".

### 4.2 One state, not a boolean and an enum

**Location:** `src/Adapter/Redis.php:62` (`$connected`), `:75` (`$state`), `:77` (`$stateData`),
and the seven places that read both: `:229`, `:288`, `:319`, `:391`, `:409`, `:452`, `:539`

`$connected` and `ConnectionState` are two sources of truth for one fact, and all nine of their
combinations are expressible. The same readiness test is spelled four times — `:229`, `:319`,
`:357` and `:452` — three of them as the full `$this->connected && (BindRead === $this->state ||
BindWrite === $this->state)` compound and `:229` as the state half alone, inside a block already
guarded by `true === $this->connected` at `:225`; its negation is a fifth spelling at `:539`.

- `ConnectionState` gains `Connected`, so the enum carries the whole fact:
  `Nothing | Connected | BindWrite | BindRead`, and `$this->connected` is deleted.
  `isReady()` (item 1.1) becomes `ConnectionState::BindRead === $this->state ||
  ConnectionState::BindWrite === $this->state`, written once.
- `$stateData` (`:77`) is **dead**: assigned `[]` at declaration, reset to `[]` at `:268`, read
  nowhere. Delete it. Verify with `grep -rn 'stateData' src/` returning nothing.
- The three legacy int constants `STATE_BINDWRITE`, `STATE_BINDREAD`, `STATE_NOTHING`
  (`:48-50`) are superseded by the enum and read nowhere in `src/`. They are public constants, so
  they are **deprecated in 5.x and removed in 6.0**, with a `UPGRADING` line — the enum's docblock
  already explains that its int values exist so that "existing integer comparisons keep working",
  and after this change nothing in the library does that any more.
- The four spellings of the guard become `isReady()` (and `:539` its negation), and the test
  helper `RedisAdapterCoreTest::setState()` (`:392`) is updated to set one property.

### 4.3 `MySql` becomes instantiable, and its connection is the caller's to replace

**Location:** `src/Adapter/MySql.php:69`, `:76`, `:245-252`, `:269-281`

- `abstract class MySql` → `class MySql`. It implements all 11 abstract methods; the `abstract` is
  decoration. Not `final` — that would break `TestMySqlAdapter` and any user subclass, and there is
  nothing to gain from it. Delete `tests/Support/TestMySqlAdapter.php` and point
  `MySqlAdapterTest::adapter()` at `MySql` directly.
- **Reconnect.** `MySql.php:238-242` documents the limitation honestly: the mysqli driver cannot
  reconnect, a dead link can only be replaced by a new `mysqli` built by the caller. That is a
  real resilience gap — a worker whose link drops is dead until someone rebuilds it, and nothing
  in the library can help. The minimal, dependency-free fix is an optional provider on
  `JobConfig`: `public ?\Closure $connectionProvider`, called only after `ping()` fails, and the
  old link closed. The adapter stays the non-owner of the link it is handed; it just stops being
  the end of the road. This is opt-in, so it costs nothing for a caller who is happy to own the
  lifecycle, and it is the single biggest resilience win in the MySql adapter.
  If the maintainer prefers not to add state to `JobConfig`, the alternative is a
  `ReconnectableConnection` interface in `Adapter\MySql` that the `mysqli` is wrapped in — more
  code, more indirection, one more thing to mock. The closure is the KISS answer.

### 4.4 `MySql` stops answering five methods with a constant or an empty body

**Location:** `src/Adapter/MySql.php:255-258` (`hasWorkers`), `:261-266` (`setWorkTimeout`),
`:278-281` (`disconnect`), `:284-293` (`bindRead` / `bindWrite`)

`hasWorkers()` returning `true` is a lie that reaches the user: `AbstractPublisher::hasWorkers()`
(`AbstractPublisher.php:119`) will report "yes, workers are ready" for a table queue that has
none. There is no cheap honest answer — MySQL cannot count idle workers without a heartbeat
column the schema does not have — so the honest answer is a `debug` record plus `false`, which is
what `Redis::hasWorkers()` already does (and does at `info` level on every call, `Redis.php:429`,
which should be `debug`).

`setWorkTimeout()` stays an empty body, because "idle timeouts are a worker concern, the queue is
shared via the table" (`:263-265`) is a correct statement — but with a `debug` line so a reader
can tell a deliberate no-op from an oversight. `connect()`, `disconnect()`, `bindRead()` and
`bindWrite()` keep their `true`/`ping()` bodies, because for a caller-owned link they are truthful
statements, and 4.3 gives the one case where they stop being true (a dead link) a way out.

---

## Phase 5 — put the linter back on 45% of the directory

### 5.1 Remove `@phpcs:disable` from the three adapter files

**Location:** `src/Adapter/Beanstalk.php:24`, `src/Adapter/PersistentBeanstalk.php:17`,
`src/Adapter/IO/StreamIO.php:51`

Not `Beanstalk/Client.php` — see 5.2. The first pass is expected to produce findings; the point of
the phase is the count going to zero over a few commits, not one heroic reformat. In order:

1. Type the six untyped properties (`Redis.php:62`, `:110`, `Beanstalk.php:43`,
   `StreamIO.php:75`, `Redis/Queue.php:30`, `:35`) — psalm already names every one of them.
2. Delete the commented-out code (`Beanstalk.php:79`, `:120`, `Redis.php:610`,
   `Beanstalk/Client.php:215`).
3. Run phpcs on the file, and fix what it finds **scoped to that file** (AGENTS.md: a tree-wide
   `phpcbf` rewrites files this plan never opened).
4. `Redis/Queue.php:30` and `:35` redeclare `$retryAfter` and `$blockFor` over the illuminate
   parent, which is deliberate (illuminate's own `RedisQueue` declares them untyped and coerces in its
   constructor) — this
   needs a comment saying so, not a delete, and a `@psalm-suppress` if the tools disagree.

`StreamIO.php` is the one to watch: 474 lines of raw stream handling, and Plan 1/2 own it. Only the
property type, the comment removal and the formatting are in scope. If phpcs produces more than a
mechanical list there, stop and file it as Plan 8 rather than expanding this one.

### 5.2 Shrink `Beanstalk/Client.php` to the delta, and document it

**Location:** `src/Adapter/Beanstalk/Client.php` (387 lines, `@phpcs:disable` at the top)

The class already `extends \Beanstalk\Client` and overrides `__construct`, `connect`, `reserve`,
`disconnect`, `_write`, `_read`, `_statsRead` and `_decode`. Each override exists because Plans
1–3 needed different behaviour — framing, deadlines, decoding — and each one is now a maintenance
obligation against a dependency that also ships its own.

The industry-practice answer for a modified vendor class is: make the delta explicit. Concretely,
(1) a class-level docblock listing each override and the one-line reason it exists, so the next
`composer update` of `davidpersson/beanstalk` is a diff against a known list rather than a
mystery; (2) a `tests/Adapter/Beanstalk/ClientTest.php` case per override — 36 tests exist today,
so the coverage is there, the *inventory* is not; (3) `php -l` plus those tests as the gate, since
`@phpcs:disable` stays (fixing a vendored fork's formatting is not this plan's job).

Deleting the overrides that Plans 1–3 no longer need is explicitly **not** in this plan; it needs
its own measurements (protocol round trips, timeout behaviour) and its own plan number.

---

## Phase 6 — documentation

- **`UPGRADING`** — one BC entry per item that a caller can observe: the `Redis` constructor
  (2.1, with a before/after table in the style of Plan 5's), the `Beanstalk::connect()` signature
  and the dropped `$logger` parameter (1.3), the removed `Beanstalk::error()` (1.3), the ack
  semantics (0.2, 0.3 — **the most important entry in this plan**, because
  `afterWork*()` returning `true` for an unknown id is being replaced with `false` and a worker
  that was quietly acking a lost job will start raising
  `'Worker failed to acknowledge job result'`), the `E_USER_WARNING` removal (0.4), and the
  deprecations of `Redis::STATE_*` and the `AbstractWorker`/`AbstractPublisher` parameter type
  (4.2, 3.2).
- **`README.md`** — the "Adapter features" table and the "Architecture" diagram
  (`README.md:143-165`) describe the contract; they need the role interfaces, the config objects,
  and the failure policy, in the same terse register the file already uses.
- **`AGENTS.md`** — three additions: the phpcs blind spot is gone for 3 of the 4 files (so the
  "check those two with `php -l`" note needs rewriting); the `attempt()` helper is the one place a
  failure policy is written; and the six class-level `@psalm-suppress` annotations that exist for the
  `?->logger` call style (9 in `src/Adapter/` in total) are now fewer if phase 1 removed sites — **verify, do not assume** (`grep -rn 'psalm-suppress' src/`).
- **`plans/`** — this file's status block moves to `implemented` as each phase lands, with the
  same "amended while implementing" note Plan 6 carries, because the inventory above is a snapshot
  and `src/` is in flight.

---

## The Symfony question, answered per candidate

Installed in `vendor/`: `config`, `console`, `dependency-injection`, `deprecation-contracts`,
`filesystem`, `finder`, `http-foundation`, `process`, `service-contracts`, `string`,
`var-exporter`, `translation` (all but `console`, `http-foundation` and `process` arrive
transitively through `illuminate`). Direct requirements today: `console`, `http-foundation`,
`process`.

| Component | Verdict | Reason |
|---|---|---|
| `symfony/options-resolver` (**not** installed) | **no** | `MySql\JobConfig` already is the resolver, in 39 lines, with 7 tests that read as a specification. A second way to validate configuration is the opposite of KISS. |
| `symfony/clock` (**not** installed) | **no, here** | PSR-20 with `MockClock` is the right tool for `AbstractWorker`'s `time()` and `StreamIO`'s `usleep()`, which are out of scope. For `MySql` it is wrong: item 2.3 puts the timestamp in the SQL, where the server's clock owns it. |
| `symfony/string` | **no** | Nothing left to normalise. Plan 1 already removed the truncated-payload path that would have wanted `u()`. |
| `symfony/deprecation-contracts` (installed, transitively) | **needs a decision** | `trigger_deprecation()` is the sanctioned way to emit a runtime deprecation, and items 3.2 and 4.2 have two deprecations to emit. But it is implemented as `trigger_error(..., E_USER_DEPRECATED)`, which **AGENTS.md forbids by name**. Either (a) keep the docblock-only deprecation, which is what the `setWorkTimeout()` deprecation in this series does, or (b) adopt the package as a direct requirement and amend that AGENTS.md rule in the same commit, naming the exception. Option (a) is the default; (b) is a maintainer call, and this plan does not make it. |
| `symfony/dependency-injection` (installed, transitively) | **no** | The adapters have two constructor arguments and are built in the user's own composition root. A container adds a config file to avoid a `new`. |
| `symfony/console` (direct) | **already used** | `AbstractWorker` installs a `ConsoleLogger` by default. Nothing to reuse inside the adapters; the logger is injected. |
| `symfony/process` (direct) | **n/a** | It belongs to `Message\Process`, not to a queue adapter. |
| `symfony/http-foundation` (direct) | **stays** | Still the return type of the illuminate `ExceptionHandler::render()` the Redis adapter must implement (`:150`). Dropping the handler entirely would be the only way to drop the dependency, and illuminate's `Manager` requires the binding. |

**Net: this plan adds no runtime dependency.** The two value objects replace a parameter list and
an array; the one Symfony question it cannot answer is deprecation signalling, and that is a
policy decision, not a technical one.

## Verification

Baseline to attribute the diff against, measured on this branch: **326 tests, 804 assertions, 3
expected `opis/closure` deprecations**, final line `OK, but there were issues!`. The adapter
directory's 199 tests are `Adapter/*` plus `Adapter/{Beanstalk,MySql,Redis,IO}/*`. The contract
has 14 call sites, 8 in `AbstractWorker` and 6 in `AbstractPublisher`; after phase 3 that number
must not change.

```bash
task=$(cat <<'EOF'
cd /app || exit 1

# Phase 0 — the three defects stay fixed
grep -n "' = ' \. \$jobId" src/Adapter/MySql.php && echo "FAIL: unquoted id is back" || echo "OK: pickTask quotes the id"
grep -n "0 <= \$this->write" src/Adapter/MySql.php && echo "FAIL: vacuous ack is back" || echo "OK: updateState no longer compares against 0"
grep -rn "trigger_error" src/ && echo "FAIL: trigger_error is back" || echo "OK: no trigger_error in src/"

# Phase 1 — one policy, one log line
grep -c "adapter ' \. __FUNCTION__ \. ' exception: " src/Adapter/Beanstalk.php   # expect 0
grep -rn "public function error(" src/Adapter/Beanstalk.php && echo "FAIL: the bridge is back" || echo "OK: no error() bridge"

# Phase 2 — configuration is a value object
grep -n "date('Y-m-d H:i:s')" src/Adapter/MySql.php && echo "note: PHP-side clock still present" || echo "OK: the DB owns the timestamp"
grep -n "NOW()" src/Adapter/MySql.php

# Phase 3 — the contract did not move
grep -c "abstract public function" src/Adapter/AbstractAdapter.php              # expect 11
grep -n "adapter->" src/Worker/AbstractWorker.php src/Publisher/AbstractPublisher.php | wc -l   # expect 14
grep -rn "implements QueueConsumer\|implements QueueProducer" src/ tests/

# Phase 5 — the linter sees the files
grep -rln "@phpcs:disable" src/Adapter/        # expect only Beanstalk/Client.php
grep -rn "^\s*\(private\|protected\|public\) \$" src/Adapter/ && echo "FAIL: untyped property" || echo "OK: every property typed"

php -l src/Adapter/MySql.php
php -l src/Adapter/Redis.php
php -l src/Adapter/Beanstalk.php
php -l src/Adapter/AbstractAdapter.php
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src tests --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src tests
php -d memory_limit=-1 vendor/bin/psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true src
php ./vendor/bin/phpunit --configuration=phpunit.xml
echo "SENTINEL: reached end"
EOF
)
docker exec app-php83 bash -c "$task"
```

No `set -e`: every step must report even after an earlier one fails, and the sentinel must print
before the run is trusted (AGENTS.md). Every `grep` above is a **negative** assertion — each one
names a shape this plan deletes, so a green run with a hit means the phase was reverted, not that
the phase worked. After each phase, `grep -rn "<the symbol you removed>" src/` must return
nothing, and the new test count must be attributable line by line: phase 0 adds 5, phase 3 deletes
1 (`TestMySqlAdapter`) and adds 0, phase 4.2 removes no test but changes `RedisAdapterCoreTest`'s
reflection helper.

`Beanstalk.php`, `Beanstalk/Client.php` and `StreamIO.php` carry `@phpcs:disable` until phase 5,
so phpcs is **not** a gate for changes in them — `php -l`, `check-classes` and the tests are.

## Risks for the implementing agent

- **Phase 0.3 is the one that can break a running deployment.** An ack that used to return `true`
  for an unknown id now returns `false`, and `AbstractWorker.php:309` turns a false ack into a
  thrown `'Worker failed to acknowledge job result'`. A deployment that has been quietly acking
  lost jobs will start raising that exception, and it will look like this plan caused an outage.
  Instrument first: the `debug` line in 0.3 plus `LogAssertions::assertLogged()` in the test
  tells you whether the path was ever taken; grep the maintainer's own logs for the old `true`
  acks before shipping. If the path turns out to be hot, the honest follow-up is a fix for
  whatever produces the unknown id (a stale `retry_after`, a double pick), not a revert of 0.3.
- **Phase 1's helper must not swallow a `pickTask()` failure.** `Beanstalk::pickTask()`
  (`Beanstalk.php:245-249`) rethrows today on purpose, per Plan 2. If `attempt()` catches it, the
  worker sees `false` and reads it as an idle cycle — a silent stall, which is the failure mode
  Plan 2 was written to remove. Hence `attemptRethrowing()` in 1.1: run the helper over the whole
  phase, and grep for `rethrow` before declaring it done.
- **`Closure` in a protected method signature will draw three linters.** `PHPMD.CouplingBetweenObjects`,
  psalm's `MissingClosureReturnType` and possibly phpcs. All three have precedent in this codebase
  (`Redis.php:37` suppresses the PHPMD one); suppress narrowly, on the method, and note it in the
  `UPGRADING` — do **not** widen an existing file-level suppression to cover the new method.
- **Phase 2.1 and 2.2 are source-breaking for `Redis` and `Beanstalk` users.** Named arguments
  make them feel worse than they are, and `UPGRADING` needs the before/after table. The additive
  variant (keep the parameters, build the object, switch in 6.0) costs one extra constructor and
  buys a minor release; take it unless the maintainer says 5.x is not going out.
- **`Redis::connect()` becoming real changes when errors surface.** Today a Redis outage shows up
  at `bindRead()`; after 4.1 it shows up at `connect()`, which `AbstractWorker::doStart()`
  (`AbstractWorker.php:192`) and `AbstractPublisher::start()`
  (`AbstractPublisher.php:90`) both treat as a start-up failure. Tests that call
  `new Redis($logger)` + `setState()` by reflection (15 cases in `RedisAdapterCoreTest`) bypass
  `connect()` entirely and keep passing — do not "fix" them to go through the real method, or the
  unit suite will need a live Redis. The integration suite
  (`RedisAdapterTest.php`, 1 test) is where 4.1 is genuinely exercised.
- **`ConnectionState` gaining a case is a public-enum change.** Anything that exhausts it
  (`match` without a `default`) breaks. Verified: only `Redis.php` references it, 16 times, all `===` comparisons,
  and there is no `match` expression anywhere in `src/`, so nothing in-tree breaks. Say so in
  `UPGRADING` anyway.
- **The inventory in this document is a snapshot of a moving tree.** Re-run the measurement
  commands at the top before starting, and treat the verification greps as the source of truth.
  In particular the "199 adapter tests" and "62 `logger->` sites" numbers change as soon as
  another adapter change lands, and a phase that assumes a specific count will read the wrong
  diff.
- **`declare(strict_types=1)` is only on `MySql.php`.** Do not add it to `Redis.php` or
  `Beanstalk.php` in passing. It is per-file and governs the calls that file *makes*: adding it
  to `Redis.php` would type-check every illuminate call the adapter makes, against illuminate's
  own loose signatures, and a strict `int` passed to a `float` parameter would start throwing at
  runtime. If uniformity is wanted, it is its own item with its own blast radius — `Beanstalk.php`
  is the safe candidate, because the only thing it calls is the in-repo client, whose parameters
  are untyped.
