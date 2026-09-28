# AGENTS.md

Guidance for AI agents and contributors. Rules marked MUST / MUST NOT are load-bearing:
each was measured on this host, and breaking one yields a wrong or false result rather
than an error.

BackQ publishes jobs to Beanstalkd, Redis or a MySQL table for long-running
workers, and also runs PSR-7 requests through Guzzle and OS processes.

## MUST: all build and test work happens in the container

The host is Ubuntu 24.04 (WSL2); the repo is bind-mounted into container `app-php83` at
`/app` (PHP 8.3.33, `vendor/` installed, `redis` healthy). Host PHP is 8.3.6
without `mbstring`, so PHPUnit will not even start there.

- MUST run everything that executes project code — `php`, `composer`, `phpunit`, `phpcs`,
  `phpcbf`, `phpstan`, `psalm.phar`, `phan`, `phpmd`, `pdepend`, `pre-commit` — inside
  `app-php83`; the tools are built against the image's extensions and config. On the host
  only the docker-driven composer scripts are safe: `app-up`, `app-down`, `app-tests`,
  `app-classes-docker`.
- Host `git`, `grep`, `sed`, `awk`, `find` and `python3` are fine — they see the same
  bind-mounted files, so a host edit is live at `/app` at once. Keep files LF;
  `core.fileMode=false` already keeps the 9p mount's 777 modes out of the diff.

Once per session, if `docker ps` does not list `app-php83`, run `composer app-up`. If
`vendor/autoload.php` is missing, run `docker exec app-php83 bash -lc 'cd /app && composer install'`.

The services pin `container_name`, so a `Conflict. The container name … is already in use`
error means another compose project is squatting the names: `docker rm -f app-php83
backq-redis`, then `composer app-up`.

### The one command shape

Pass the whole script as one `bash -c` argument. It arrives as an argument, not on
stdin, so no tool can eat the rest of it:

```bash
task=$(cat <<'EOF'
cd /app || exit 1
php -l src/Adapter/Beanstalk.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache src/Adapter/Beanstalk.php --report=full
echo "SENTINEL: reached end"
EOF
)
docker exec app-php83 bash -c "$task"
```

- MUST NOT use `docker exec -i app-php83 bash -s`. Bash reads a script from stdin lazily,
  so any tool that drains stdin eats the rest of it; `phpcs` does. The run then **looks
  green while every later command silently never ran** — the most dangerous failure mode
  here. Reproduce it with `php -r 'while(fgets(STDIN)){}'`.
- MUST NOT use `set -e` in a multi-step task. Every step must report even after an earlier
  one fails, so `echo` a sentinel on the last line and confirm it printed before trusting
  the run. `set -u` is fine.
- For a long sweep, stage the scripts in the gitignored `/app/tmp/` so it can be
  edited and re-run: `docker exec app-php83 bash /app/tmp/sweep.sh`. The container's `/tmp`
  is tmpfs and does not survive a restart.
- `/app/tmp/` is gitignored and excluded from phpcs; keep scratch PHP out of `/app/src/` and `/app/tests/`.

## Verifying a change

### Run order

Narrow first, then wide. The narrow pass is what surfaces the PHPStan trap below; the
wide pass is what proves you did not break a neighbour.

1. `php -l` on every file you touched.
2. If you **added or renamed a class, interface, trait or enum**, run
   `composer dump-autoload` in the container first. `classmap-authoritative` is on, so an
   un-dumped class is not autoloadable and the suite dies with `Trait "..." not found`
   instead of a test failure.
3. `php build/check-classes.php` — links every class in an isolated child process. This is
   the only step that catches a load-time fatal; `php -l` does not.
4. `phpcs` and `phpstan` on the changed files.
5. `psalm.phar` on the changed `src/` files.
6. `phpcbf`, **scoped to your own files** — see the scoping rule below.
7. Repeat steps 4–5 over all of `src tests`.
8. `phpunit`, full suite, last.
9. `git status --short`, then `git diff` anything unexpected it names.
10. `grep` the symbol you added or removed and expect the right answer in each direction.

### The suite needs two services, not one

`tests/Adapter/MySqlLiveTest.php` is the first test in this repository that executes a MySQL
statement, and `phpunit.xml` sets `failOnSkipped="true"`, so its reachability guard turning
into a skip is a **failed run**. `build/docker-compose.yaml` therefore carries `mysql80`
alongside `redis`, and `app-php83` waits for it with `condition: service_healthy` — a
host-side `composer app-tests` now needs both services, not just Redis. That is the honest
consequence of the live tests, not a regression; if you are on the host, export
`BACKQ_MYSQL_PORT=4306` (the published port) alongside the existing `BACKQ_REDIS_PORT`.

### Commands

| Command (in the container) | Pass signal |
|---|---|
| `php -l <file>` | `No syntax errors detected` |
| `composer dump-autoload` | `Generated optimized autoload files` |
| `php build/check-classes.php` | `OK, every class file loads` |
| `phpcs --standard=build/phpcs-ruleset.xml --no-cache -s <file>… --report=full` | 0 errors |
| `phpcbf --standard=build/phpcs-ruleset.xml --no-cache <file>…` | `No violations were found` |
| `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon <file>…` | `[OK] No errors` |
| `psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true src/<file>` | `No errors found!` |
| `php ./vendor/bin/phpunit --configuration=phpunit.xml` | a final summary line, exit 0 |

`--no-cache` on phpcs is mandatory, else stale `tmp/phpcs-tempfile` results come back.
`phpcbf` is an idempotency check, not a fixer: any output means violations remain. Run the
tools individually rather than via `composer app-code-quality` — that script aborts at
`app-phpstan` (see the traps), so it never reaches psalm or phan. It is a container-side
script; on the host, never.

### Scope each tool to what it can actually gate

- **phpcbf over `src tests` rewrites every violation it finds, including in files you never
  touched.** It does not know which edits are yours. Scoped to the whole tree during
  concurrent work, it will silently reformat a file someone else is mid-edit on, and the
  resulting diff mixes their change with yours. Pass it your own files, and `git diff`
  anything it names before you accept it. Note it cannot invent content: a copyright-year
  bump or a docblock edit in the diff is not phpcbf's work, so read the hunk before
  blaming or reverting it.
- **Psalm's gate is `src`.** `tests/` carries a pre-existing baseline of findings
  (`MissingOverrideAttribute`, `PossiblyUndefinedStringArrayOffset`, `InternalMethod` on
  `addToAssertionCount`, `UndefinedMethod` on the `mysqli` mock). Running psalm over
  `tests` therefore always prints errors; they are not a regression. Run it on the test
  files you changed and confirm the hits are on lines you did not write.
- **phpstan's gate is `src tests`,** and its result depends on the file set — see the trap
  below.

### Prove the run was real

- **No summary line means the run did not finish.** PHPUnit stopping after a run of dots,
  with no summary and exit 255, is a load-time fatal, not a test failure. The same is true
  of a sweep whose sentinel never printed.
- **Record the test and assertion counts before and after**, and attribute every delta.
  A silent count drop is a deleted test, not a fix. In this repo the three
  `opis/closure` deprecations are expected, so `OK, but there were issues!` is the healthy
  final line — `OK (…)` with the deprecations missing means something changed the
  serialization path.
- **A green run does not prove a symbol is gone.** After a removal, `grep -rn <symbol>
  src/` and expect nothing. After a rename, grep both the old and the new name. A suite
  that no longer references the symbol also no longer exercises it.
- **A clean run does not prove a new rule fires.** When you add a phpcs sniff or a
  forbidden-function entry, prove it with a throwaway file that violates it, then delete
  it in the same script. The probe must live inside `src/` — gitignored `/app/tmp/` is
  excluded from phpcs, so a probe there silently checks nothing. Confirm the `@`-silenced
  form is caught too if the rule is supposed to see through silencers.

## Architecture

- `src/` — library code, PSR-4 `BackQ\`
  - `Adapter/` — `AbstractAdapter` is the base contract; concrete `Redis`,
    `Beanstalk`, `MySql`. Alongside: the `ConnectionState` enum, `Redis/`, `IO/`
  - `Worker/` — extend `AbstractWorker`, implement `run(): void`
  - `Publisher/` — extend `AbstractPublisher`, pass the adapter to the constructor. A
    publisher embedded in a serialized message must rebuild its adapter in `__wakeup()`
  - `Message/` — payloads implementing `ConsumeInterface`
- `build/` — quality config (`phpcs-ruleset.xml`, `phpstan.neon`, `psalm.xml`, `phan.php`,
  `phpmd-rulesets.xml`, `pdepend.xml`, `stubs/`, `check-classes.php`,
  `.pre-commit-config.yaml`) and the dockerized dev env
- `example/` — runnable examples, not shipped. `plans/` — implementation plans. As implemented:
  1-7, 9 and 11 are done. 8 (`plan-8-redis-has-workers-local-registry.md`) is **proposed and not
  started** — it is the *alternative* to the implemented plan 9, not a step after it. Plan 10
  (`plan-10-mysql-has-workers-named-lock.md`) is the same shape for `MySql::hasWorkers()` and is
  **also proposed and not started**, also the alternative to plan 11 rather than a step after it.
  Its problem statement is *historical*: `hasWorkers()` was a stub when plan 10 was written and is
  now answered from a table, so plan 10 is no longer the fix for anything — what survives of it is
  the decision "the `CREATE TABLE` is unacceptable in my deployment". All three proposed plans
  shared a prerequisite their predecessors did not, and plan 11 removed it: **there was no MySQL
  service in `build/docker-compose.yaml`, so this repository had never executed one MySQL
  statement**, and every test in `tests/Adapter/MySqlAdapterTest.php` asserted a string against a
  `createMock(mysqli)` rather than a result. There is a `mysql80` service and a
  `tests/Adapter/MySqlLiveTest.php` now — so the container is required for a green suite, not just
  Redis (see "The suite needs two services, not one"). The implemented ones are historical records,
  and a historical record can be wrong about its own present: plans 1-5 still describe the `Nsq`
  adapter, which 5.x removed (see `UPGRADING`), `plan-5` still says
  `AbstractAdapter::JOBTTR_DEFAULT` stayed, which it did not, `plan-6` still counts 9
  `error_log()` call sites in 4 files — 3 of them went with the `Amazon\SNS` workers, so 6
  remained, all in `AProcess`, and `plan-7`'s own status block now contradicts the "proposed and
  not started" line this list used to carry. Read a plan's status block before trusting its body,
  and expect a shipped plan's sketches to be right about the design and wrong about the plumbing:
  plan 9 ends with a "Corrections this plan needed" section because four of its code sketches did
  not survive contact with the container, and plan 11's has **fourteen**, seven of which a mocked
  suite cannot catch.
- `.github/workflows/ci.yml` builds the same php83 image and runs the same check-classes
  and PHPUnit steps on every PR, so a green local sweep matches CI

### The putTask variance rule

`AbstractAdapter::putTask(string|Stringable $body): null|string|Throwable` — one parameter,
no options array. An adapter may only **append optional** parameters: PHP makes a required
extra parameter, a narrowed parameter, or `false` in the return union a load-time fatal. A
transport or storage failure is **returned** as a `Throwable`, never `false`; an invalid
argument still **throws**. `AbstractPublisher::publish()` is variadic and forwards named
arguments (`publish($message, readyWait: 5)`). Per-adapter signatures and rationale:
`UPGRADING`.

### The one place a failure policy is written

That return-vs-throw rule above is the contract; `AbstractAdapter` is the **only** place it is
implemented, in the `attempt*` family. Do not hand-roll a try/catch that logs and returns
`false` — call the helper that matches the operation:

| Helper | For | On `Throwable` |
|---|---|---|
| `attempt($op, $body)` | `ping()`, `hasWorkers()`, `afterWork*()` | logs, returns `false` |
| `attemptConnect($op, $body)` | `connect()` | logs, returns `false` |
| `attemptReturning($op, $body)` | `putTask()` | logs, returns the `Throwable` |
| `attemptRethrowing($op, $body)` | `pickTask()` | logs, then **rethrows** |

`attemptRethrowing` is the pick because a worker reads a `false` pick as an idle cycle, so a
transport failure must not look like one — it is logged and rethrown, and the worker decides
what it means. `attemptReturning` returns the original `Throwable` so `putTask()` keeps the
caller's type, message and previous chain; a **missing precondition** there also answers a
`RuntimeException`, because that signature admits no other failure.

Every one of them logs through `log()` at `error` with `['exception' => $e]` in the context,
which is what carries the class, stack and previous chain to the handler — so a handler that
matched on a message prefix the adapter used to write no longer works; match on `exception`.
`isReady()` is the "is this adapter bound" precondition and `preconditionFailed()` its log;
`attempt` and `attemptRethrowing` gate on it themselves, which is why `pickTask()` on an
unbound adapter returns `false` rather than reaching an uninitialised property.
`attemptConnect` does **not** gate, because connecting is the one operation with no
precondition to check. `Redis::queue()` is the companion accessor: it throws
`RuntimeException` when `$queue` is null, and that is an invariant break which must never
escape uncaught, so a psalm `RedundantPropertyInitializationCheck` on `$this->queue` is a real
report, not noise to suppress.

  **`MySql::hasWorkers()` is a `SELECT`, and `write()` answers `0` for anything that produced
  a result set** (`src/Adapter/MySql.php` `write()`). It frees the result and returns
  `max(0, affected_rows)`, so a `hasWorkers()` built on it reports "no workers" on every
  call, forever, with `MySqlAdapterTest` fully green behind it — the mock answers every
  statement with a `mysqli_result`, so `write()`'s `0` is exactly what the mock returns.
  Only `MySqlLiveTest` reaches a real server. This is the MySQL twin of the `getRedis()`
  trap, and the rule is the same: **the offline layer asserts the string, and a string being
  right is not the statement being run.** `select()` and its `instanceof mysqli_result`
  narrowing are what stand between a result set and a wrong answer; do not "simplify" it.
  `mysqli::query()` has **no declared return type** (measured by reflection), so that
  narrowing is the only thing psalm has to go on.

  Two more from the same adapter, both measured against MySQL 8.0.46 rather than quoted:
  **`VALUES(col)` in `ON DUPLICATE KEY UPDATE` raises `Warning 1287`** on 8.0.20+, and the
  8.0.19+ row-alias spelling that replaced it would put a version floor on the adapter. The
  lease therefore names the queue literally in the `UPDATE` clause, which needs neither and
  raises nothing. And **`ER_NO_SUCH_TABLE` is 1146, not 1144** — 1144 is a missing storage
  engine, and 1205 is the lock-wait timeout. `hasWorkers()` de-duplicates on 1146 alone, so
  getting that number wrong turns every unrelated failure into one `debug` line per process.

  **`backq_workers.token` is `varbinary(16)`, and that is a constraint on the value, not a size
  setting.** The adapter sends the token as `UNHEX()` of its 32 hex characters, and a `utf8mb4`
  `varchar` refuses the result outright — `ERROR 1366 (HY000): Incorrect string value` for column
  `token`, measured. So a table created from an older copy of the DDL does not merely carry a
  wider index: **it rejects every lease write**, inside `attempt()`, as one logged `error` per
  interval per worker and a table that never fills. Three rules come with it, all measured:
  - **Never interpolate the 16 raw bytes.** A random byte is `0x00` half the time and `0x22` half
    the time, so the raw form ends the literal early — the *first* token raised
    `syntax error … near '??ua?|", NOW())' at line 1`. Only the hex goes in the statement.
  - **No dashes inside `UNHEX()`.** It is not a parser: it answers `NULL` for anything that is not
    pairs of hex digits, and `NULL` into a `NOT NULL` column is a second, quieter failure. The
    dashed spelling is the log line's problem, not the statement's.
  - **The token is a UUIDv7 and it is *not* monotonic.** 20,000 minted back to back came out
    strictly ascending 50.1% of the time — within one millisecond the order is the random bits,
    as RFC 9562 specifies. That would buy insert locality only for a *clustered* key, and the
    clustered key here is the auto-increment `id`; `token` is a secondary UNIQUE key read by
    equality. The width (256 bytes per key down to 16) and a legible `SELECT HEX(token)` are what
    it buys — **82 KB on 10,000 rows by `ANALYZE TABLE`, not a factor of sixteen**, because the
    old tokens were 20–32 characters rather than 64. Do not claim locality for this table.
  The token's embedded milliseconds are **PHP's** clock and `seen` is the **server's**; nothing
  compares them, so a host with a wrong clock writes a wrong time in the token and a right expiry.
  It is a diagnostic, never a source of truth.

  **The registry's reap is on a schedule, so the index on `seen` alone is not a copy of the
  one on `(queue, seen)`.** The reap is the only statement in the feature that is not
  queue-scoped, so it filters on `seen` by itself, and it is the only one the
  `(queue, seen)` index cannot serve. Measured on 8.0.46 with `EXPLAIN`: with
  `backq_workers_seen` the `DELETE` is `type=range` on that key, and without it the same
  statement is `type=ALL`, `key=NULL`, **`Using filesort`** — a scan *and* a sort, because
  `ORDER BY seen` then has nothing to read in order. The count is unaffected by that drop
  (`type=index`, `key=backq_workers_queue_seen`, `Using index`), which is what makes the two
  keys distinct rather than one duplicated. The DDL is in four places — the `MySql` class
  docblock, `README.md`, `UPGRADING` and `tests/Adapter/MySql/Schema.php` — and a fifth copy
  arrives in any deployment that copied it from an older README, so read the key list rather
  than assuming. `MySqlLiveTest` asserts the plan with `EXPLAIN`, which is the only assertion
  in the file that needs a populated table to mean anything: `type` and `key` are the server's
  choice, `rows` is a guess against a table the fixture just emptied.

  **A reap nested inside the lease's `attempt()` is a silent leak, and the mock cannot see
  it.** `announce()` runs the lease and the reap as two separate `attempt()` calls, and
  `bindRead()` and `renew()` both go through it. One `attempt()` around both would make the
  first failure skip the second, which is exactly the deployment the plan's own text worried
  about: a user granted `SELECT`/`INSERT`/`UPDATE` but not `DELETE`. The leases keep working,
  the picks keep working, `hasWorkers()` keeps answering correctly, and the table grows
  forever with one `error` line per interval per worker. `attempt()` logs every `Throwable` at
  `error` unconditionally, so "it is only in the log" is the whole of the symptom.

  **`bindRead()` runs once per process, before the worker's `while (true)`** (`src/Worker/AbstractWorker.php`
  `:193`, the loop is at `:263`). That is why the reap lives in `renew()` as well as in
  `bindRead()`: a worker that binds once and then runs for months never starts again, so a
  startup-only reap leaks one row per crash in exactly the deployments — a stable long-lived
  fleet — where nobody is restarting anything to notice. Measured before the fix: 7035 pick
  cycles over three seconds left a 4000-second-old row untouched at a 15-second threshold.
  It read `hasWorkers() === false` throughout, so the answer was never wrong — the table just
  never shrank. The regression test is
  `MySqlLiveTest::testARunningWorkerReapsADeadPeerWithoutRestarting`, and its ordering is the
  test: a reap on the *first* pick would pass against the old code and prove nothing, so the
  dead peer is seeded after the startup reap and the assertion waits for a pick that is past
  the interval.

  `Queue::getRedis()` is the other one, and it costs more: it is **declared**
  `Illuminate\Contracts\Redis\Factory` and **answers a `BackQ\Adapter\Redis\Manager`**, whose
  `__call` forwards to the client. So any value from it carries a native `\Redis` type as a
  **runtime check**, not as documentation — a return type or a parameter type narrower than the
  union is a `TypeError` at call time, and `php -l`, `check-classes.php` and psalm all pass it.
  Worse, `RedisAdapterCoreTest` wires a `createMock(\Redis::class)`, so **the whole offline suite
  stays green while the feature is broken in production**: only `RedisAdapterTest` reaches a real
  `Manager`. Declare the union (`Factory|\Redis`, or `Redis\Manager` if you want the narrow
  reading) and narrow with `assert($x instanceof \Redis)` where the command is issued, exactly as
  `pingServer()` does. Note `Manager` is not a free name in `src/Adapter/Redis.php` —
  `Illuminate\Queue\Capsule\Manager` is imported there, so the file spells the other one
  `Redis\Manager`.

`false` from an acknowledgement means *the backend did not confirm the job*, not *the call
failed* — and `AbstractWorker` treats it as fatal
(`Worker failed to acknowledge job result`, which ends the cycle). Do not make a call site
swallow it.

## Traps

Each of these yields a wrong result rather than an error.

- **`php -l` misses load-time fatals, and they kill PHPUnit mid-run.** A redundant union
  (`string|false|bool`) or a narrowed inherited parameter (`_read(?int $n)` over
  `_read($n)`) passes `php -l` but is fatal on load: the suite stops after a run of dots,
  prints no summary, exits 255 — that symptom, not a test failure.
  `php build/check-classes.php` links every class in an isolated child process and names
  the file.
- **PHPStan's result depends on the file set.** Only the narrow (changed-file) run flags
  `method.childParameterType` for narrowing an inherited parameter. Type the parent to
  match when it lives in this repo; when it is vendor code — `vendor/davidpersson/beanstalk`
  for `src/Adapter/Beanstalk/Client.php` — drop the type and keep it in the docblock.
  Psalm never reports this.
- **The `php-code-phpstan` pre-commit hook can report `Failed` while printing `[OK] No
  errors`.** Benign false positive; judge the analysis output, not the hook status.
- **`phpunit.xml` sets `failOnSkipped="true"`**, so a skip fails the run. In the container
  nothing skips, because redis is up. Outside it, export `BACKQ_REDIS_PORT=16379` — the
  Redis test's default host is the Docker service name `redis`, which does not resolve on
  the host.
- **`--testdox` buffers its whole report** until the run ends, so a crashed run shows bare
  dots. Use `--debug` or `--log-junit` for per-test output before the run ends.
- **`src/Message/Generic.php` implements both `Serializable` and
  `__serialize()`/`__unserialize()`** on purpose, so payloads stay compatible with old and
  new PHP serialization. Both present means no deprecation on 8.3; do not migrate it to
  one or the other.
- **The Beanstalk tests need no beanstalkd** (in-process fake,
  `tests/Support/FakeBeanstalkServer.php`) but assert the **array key order** of the config
  `$defaults` in `src/Adapter/Beanstalk/Client.php`: host, logger, persistent, port,
  timeout. Never reorder or add to that array; read the new `context` key with `?? null`.
  `src/Adapter/Beanstalk/Client.php` is the **only** file left in `src/Adapter/` carrying
  `@phpcs:disable`, and it is there on purpose: its class docblock holds the nine-row table
  documenting every override of the vendored `davidpersson/beanstalk` client, and
  `ClientTest::testTheOverrideInventoryInTheDocblockMatchesTheCode()` asserts that table
  against the code by reflection. So for that one file `php -l` plus the tests are the gate,
  not phpcs — and the test is only worth anything if both sides are non-empty; a regex or a
  reflection filter that silently returns nothing makes it pass on two empty lists, so
  re-probe it with a deliberate mismatch before trusting a change to it. The other three
  files that used to carry `@phpcs:disable` (`Beanstalk.php`, `PersistentBeanstalk.php`,
  `IO/StreamIO.php`) are linted and gated normally — do not re-add the suppression to them.
- **Prove every `@psalm-suppress` is load-bearing by deleting it and re-running.** Psalm will
  not report an annotation as unused, so a predicted count is a guess wearing a number's
  clothes. Plan 11 predicted `MySql.php` 3 → 7 and `src/Adapter/` 14 → 18 for the six
  methods it added; all four were written, and psalm reported nothing without any of them, so
  the measured count is **3** and **14** — unchanged. Four unused annotations are four lines
  of noise, and worse, they teach the next reader a rule that is not true.
- **Do not re-enable the sniffs excluded in `build/phpcs-ruleset.xml`**:
  `AttributesOrder` needs `orderAlphabetically=true`, and `DisallowTrailingCommaInDeclaration`
  plus `DisallowNonCapturingCatch` conflict with their matching "Require" sniffs and send
  phpcbf into an infinite loop. `DisallowNullSafeObjectOperator` is excluded for the
  adapters' logging: they call `$this?->logger->error(...)` at the call site since
  `AbstractAdapter` dropped its `logInfo()` / `logDebug()` / `logError()` helpers, and the
  logger is mandatory there. That call style is why psalm needs suppressions, and the count
  is measured rather than assumed: **14** `@psalm-suppress` annotations in `src/Adapter/` —
  `AbstractAdapter` 2, `Redis` 7, `MySql` 3, `Beanstalk` 1, `Beanstalk/Client` 1. Most are
  the `?->logger` trio (`TypeDoesNotContainNull`, `PossiblyNullReference`); the
  `PossiblyNullPropertyAssignment` ones are the *same* cause one step later — a preceding
  `$this?->logger` line widens `$this` to nullable, so the assignment after it looks unsafe.
  The count **rose** from 10 when the `attempt*` family landed, because `log()` and `report()`
  are new methods with the same call style, and again from 12 to 14 when
  `Redis::hasWorkers()` gained its lease registry, whose two `is_int()` shape checks on `ZADD`
  and `ZCARD` need `@psalm-suppress TypeDoesNotContainType` — psalm reads both commands as
  always answering `int`, while phpredis declares `Redis|int|false`. **The MySQL worker
  registry did not move the count at all**, which is the exception worth knowing: `Redis`'s
  logger is a nullable property and `MySql`'s is a mandatory constructor parameter whose
  widening the class docblock's two annotations already cover, so `MySql::hasWorkers()`,
  `bindRead()`, `disconnect()`, `lease()`, `renew()` and `reap()` all log through
  `$this?->logger` and need none. So adding a method that
  logs through `$this?->logger` needs its own suppression *when the logger is a nullable
  property*, removing a log line may let one go,
  and a runtime guard on a value psalm has already narrowed to one type needs its own
  `TypeDoesNotContainType` — re-measure with `grep -rn 'psalm-suppress' src/Adapter/` rather
  than assuming a direction.
- **Three `opis/closure` deprecations are expected** — `SerializableClosure implements
  Serializable` and the dynamic `ClosureStream::$context`. They print as `D` and do not fail
  the run.
- **Redis has two test layers**: `RedisAdapterTest.php` integration tests need
  a live service, `RedisAdapterCoreTest.php` unit tests inject state via reflection and need
  none — so a failure there is a real bug.
- **Do not assert on `error_log()` output.** `src/` reports worker failures through
  PSR-3 (`AbstractWorker::logError()`), and `build/phpcs-ruleset.xml` forbids the
  `error_log` function, so a test that reads the PHP error log asserts nothing. Inject
  `BackQ\Tests\Support\RecordingLogger` via `setLogger()` and assert with the
  `assertLogged()` / `assertNotLogged()` helpers from
  `BackQ\Tests\Support\LogAssertions`. A negative assertion needs a `RecordingLogger`,
  never a `NullLogger` — against a null logger it is vacuous.
- **`classmap-authoritative` is on**, so a **new** class or trait is not autoloadable
  until `composer dump-autoload` runs in the container — step 2 of the run order. An
  un-dumped class fails the suite with `Trait "..." not found`, not with a test failure.
- **`build/stubs/RedisManager.stub`** is a Psalm stub for `Illuminate\Redis\RedisManager`,
  referenced from `build/psalm.xml`; keep it in sync if the illuminate/redis API changes.

## Conventions

- PSR-12 via `build/phpcs-ruleset.xml`; 4-space indentation, LF endings
- Prefer typed properties and parameters. No comments unless they add real value
- Never commit `vendor/` or secrets; `composer.lock` **is** tracked
- New public API needs an `UPGRADING` entry
- **`trigger_error()` MUST NEVER be used in new code** — not in `src/`, `tests/` or `example/`.
  The rule is currently violated in 3 pre-existing call sites, all untouched by this work and all
  predating it: `Publisher/AbstractPublisher.php:54` (a deprecation notice), and
  `Worker/AProcess.php:248` and `Worker/GuzzleForwarder.php:127` (both at `E_USER_WARNING`, which
  is the one that matters — it goes to stderr, not to a PSR-3 handler, so a host that redirects
  stderr loses the message and a host that has `display_errors=Off` shows the user nothing).
  Migrating them is its own change, not a drive-by. A rule stated as absolute and broken in three
  places is worse than no rule, so the count is stated here rather than left for the next reader
  to discover with `grep`.
