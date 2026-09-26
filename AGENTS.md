# AGENTS.md

Guidance for AI agents and contributors. Rules marked MUST / MUST NOT are load-bearing:
each was measured on this host, and breaking one yields a wrong or false result rather
than an error.

BackQ publishes jobs to Beanstalkd, Redis, NSQ or DynamoDB+SQS for long-running
workers, and also pushes AWS SNS notifications, runs PSR-7 requests through Guzzle, and
runs OS processes.

## MUST: all build and test work happens in the container

The host is Ubuntu 24.04 (WSL2); the repo is bind-mounted into container `app-php83` at
`/app` (PHP 8.3.33, `vendor/` installed, `redis` + `nsq` healthy). Host PHP is 8.3.6
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
backq-redis backq-nsq`, then `composer app-up`.

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
- For a long sweep, stage the script in the gitignored `tmp/` (`/app/tmp/`) so it can be
  edited and re-run: `docker exec app-php83 bash /app/tmp/sweep.sh`. The container's `/tmp`
  is tmpfs and does not survive a restart.
- The image has no `rg` and no `jq`: use `grep -rnE`, `sed`, `awk`, `find` and `python3`
  — the last for exact-string edits, always asserting the anchor so a missed match fails
  loudly. `tmp/` is gitignored and excluded from phpcs; keep scratch PHP out of `src/`
  and `tests/`.

## Verifying a change

Run each row against the **changed files** first — that narrow set is what surfaces the
PHPStan trap below — then again over `src tests` before calling the task done.

| Command (in the container) | Pass signal |
|---|---|
| `php -l <file>` | `No syntax errors detected` |
| `php build/check-classes.php` | `OK, every class file loads` |
| `phpcs --standard=build/phpcs-ruleset.xml --no-cache -s <file>… --report=full` | 0 errors |
| `phpcbf --standard=build/phpcs-ruleset.xml --no-cache src tests` | `No violations were found` |
| `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon <file>…` | `[OK] No errors` |
| `psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true <file>…` | `No errors found!` |
| `php ./vendor/bin/phpunit --configuration=phpunit.xml` | a final summary line, exit 0 |

`--no-cache` on phpcs is mandatory, else stale `tmp/phpcs-tempfile` results come back.
`phpcbf` is an idempotency check, not a fixer: any output means violations remain. The
suite is healthy only when it prints a summary. Run the tools individually rather than via
`composer app-code-quality` — that script aborts at `app-phpstan` (see the traps), so it
never reaches psalm or phan. It is a container-side script; on the host, never.

## Architecture

- `src/` — library code, PSR-4 `BackQ\`
  - `Adapter/` — `AbstractAdapter` is the base contract; concrete `Redis`, `Nsq`,
    `DynamoSQS`, `Beanstalk`, `MySql`. Alongside: the `ConnectionState` enum, `Redis/`,
    `IO/`, `Amazon/DynamoDb/QueueTableRow`
  - `Worker/` — extend `AbstractWorker`, implement `run(): void`
  - `Publisher/` — extend `AbstractPublisher`, pass the adapter to the constructor. A
    publisher embedded in a serialized message must rebuild its adapter in `__wakeup()`
  - `Message/` — payloads implementing `ConsumeInterface`
- `build/` — quality config (`phpcs-ruleset.xml`, `phpstan.neon`, `psalm.xml`, `phan.php`,
  `phpmd-rulesets.xml`, `pdepend.xml`, `stubs/`, `check-classes.php`,
  `.pre-commit-config.yaml`) and the dockerized dev env
- `example/` — runnable examples, not shipped. `plans/` — implementation plans; 1-5 are
  implemented, `plan-6-error-log-deprecation.md` is proposed only
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
  nothing skips, because redis and nsqd are up. Outside it, export `BACKQ_REDIS_PORT=16379`
  and `BACKQ_NSQD_HOST=127.0.0.1 BACKQ_NSQD_PORT=14150` — the NSQ test's default host is
  the Docker service name `nsq`, which does not resolve on the host.
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
  phpcbf into an infinite loop.
- **Three `opis/closure` deprecations are expected** — `SerializableClosure implements
  Serializable` and the dynamic `ClosureStream::$context`. They print as `D` and do not fail
  the run.
- **Redis and Nsq each have two test layers**: the `*AdapterTest.php` integration tests need
  a live service, the `*AdapterCoreTest.php` unit tests inject state via reflection and need
  none — so a failure there is a real bug.
- **`build/stubs/RedisManager.stub`** is a Psalm stub for `Illuminate\Redis\RedisManager`,
  referenced from `build/psalm.xml`; keep it in sync if the illuminate/redis API changes.

## Conventions

- PSR-12 via `build/phpcs-ruleset.xml`; 4-space indentation, LF endings
- Prefer typed properties and parameters. No comments unless they add real value
- Never commit `vendor/` or secrets; `composer.lock` **is** tracked
- New public API needs an `UPGRADING` entry
- Work on a dedicated branch, land as a pull request
