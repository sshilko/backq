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
- `example/` — runnable examples, not shipped. `plans/` — implementation plans; 1-6 are
  implemented. They are historical records: plans 1-5 still describe the `Nsq` adapter, which
  5.x removed (see `UPGRADING`), `plan-5` still says `AbstractAdapter::JOBTTR_DEFAULT` stayed,
  which it did not, and `plan-6` still counts 9 `error_log()` call sites in 4 files — 3 of them
  went with the `Amazon\SNS` workers, so 6 remained, all in `AProcess`. Read a plan's status
  block before trusting its body.
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
  timeout. `Beanstalk.php` and `Client.php` carry `@phpcs:disable`, so phpcs skips them
  entirely — check those two with `php -l` and the tests.
- **Do not re-enable the sniffs excluded in `build/phpcs-ruleset.xml`**:
  `AttributesOrder` needs `orderAlphabetically=true`, and `DisallowTrailingCommaInDeclaration`
  plus `DisallowNonCapturingCatch` conflict with their matching "Require" sniffs and send
  phpcbf into an infinite loop. `DisallowNullSafeObjectOperator` is excluded for the
  adapters' logging: they call `$this?->logger->error(...)` at the call site since
  `AbstractAdapter` dropped its `logInfo()` / `logDebug()` / `logError()` helpers, and the
  logger is mandatory there.
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
- **`trigger_error()` MUST NEVER be used** — not in `src/`, `tests/` or `example/`
