# AGENTS.md

Guidance for AI agents and contributors working in this repository.

## Project

BackQ is a PHP library for background queue processing: jobs are published to a queue
(Beanstalkd, Redis, NSQ, DynamoDB + SQS) and consumed by long-running workers. It sends
push notifications via AWS SNS, executes PSR-7 requests asynchronously via Guzzle,
and runs OS processes via `symfony/process`.

## Layout

- `src/` — library code, PSR-4 namespace `BackQ\` (see `autoload` in `composer.json`)
  - `src/Adapter/` — queue adapters. `AbstractAdapter` is the base contract; concrete:
    `Redis`, `Nsq`, `DynamoSQS`, `Beanstalk`. Shared pieces live alongside: the
    `ConnectionState` enum (Redis/Nsq state machine), `Redis/` (`App`, `Manager`,
    `Connector`, `Queue`), `Amazon/DynamoDb/QueueTableRow`, and `IO/` (`StreamIO`,
    `AbstractIO`)
  - `src/Worker/` — job workers. Extend `AbstractWorker`, implement `run(): void`
  - `src/Publisher/` — job publishers. Extend `AbstractPublisher`, implement `setupAdapter()`
  - `src/Message/` — job payloads implementing `ConsumeInterface`
- `example/` — runnable publisher/worker examples (`adapter/`, `http/`, `publishers/`,
  `workers/`; not shipped)
- `build/` — code-quality configuration and the dockerized dev environment: `psalm.xml`,
  `phpstan.neon`, `phpcs-ruleset.xml`, `phan.php`, `phpmd-rulesets.xml`, `pdepend.xml`,
  `phpdoc.xml`, `stubs/` (Psalm stub for `Illuminate\Redis\RedisManager`), `.pre-commit-config.yaml`,
  `php.ini`, `Dockerfile.php83`, `docker-compose.yaml`
- `plans/` — analysis-based implementation plans. `plan-1-protocol-tcp-frame-handling.md` is
  documented pending implementation; `plan-2-network-ssl-disconnects.md`,
  `plan-3-connection-liveness-resilience.md`, and `plan-4-minor-notes-behavior.md` have been
  implemented (plan 4 was merged via PR #11)
- `.github/workflows/` — `ci.yml` (dockerized test + quality suite) and `opencode.yml`
  (comment-triggered OpenCode runs)

## Environment

- PHP >= 8.3 (`platform.php` pinned to 8.3 in `composer.json`); `vendor/` and
  `composer.lock` are gitignored; install and verify via composer
- Changes are made on dedicated branches and land as pull requests
- The dev environment is the dockerized app in `build/` (`Dockerfile.php83` +
  `docker-compose.yaml`) with `redis` and `nsq` services. The app container is named
  **`app-php83`** (`container_name` in the compose file); the repo is mounted at `/app`
- The Redis and Nsq adapters have two test layers: `tests/Adapter/RedisAdapterTest.php` +
  `tests/Adapter/NsqAdapterTest.php` are integration tests that need a live redis/nsqd,
  while `tests/Adapter/RedisAdapterCoreTest.php` + `tests/Adapter/NsqAdapterCoreTest.php`
  are unit tests that inject state via reflection and need no service
- CI: `.github/workflows/ci.yml` builds the php83 dockerized app (with GitHub Actions
  build cache), pulls `redis:7-alpine` + `nsqio/nsq:v1.3.0`, installs deps inside
  `app-php83`, and runs `app-tests` + `app-code-quality` on every PR and merge to master.
  `.github/workflows/opencode.yml` runs OpenCode on `/oc`/`/opencode` comments from
  OWNER/MEMBER/COLLABORATOR accounts
- All lint, code-quality, static-analysis and test commands must run **inside** the
  `app-php83` docker container (PHP 8.3, repo mounted at `/app`, `vendor/` installed,
  `redis` + `nsq` services healthy). Host PHP must not be used for these checks.

## Commands

- `composer install` — install dependencies
- `composer app-classes` — check that every class file under `src/` and `tests/` links
  (see "Testing quirks"); runs on the host PHP, `composer app-classes-docker` runs it in
  the container
- `composer app-tests` — run the PHPUnit suite inside the dockerized app (`build/Dockerfile.php83`)
  with `redis` + `nsq` services from `build/docker-compose.yaml`; requires Docker
- `composer app-tests-local` — run the PHPUnit suite on the host. Caveat: `phpunit.xml` sets
  `failOnSkipped="true"`, and the Redis/Nsq integration tests skip when no service is
  reachable — so a bare host run fails on skipped tests. The dockerized `composer app-tests`
  is the reliable path
- `docker compose -f build/docker-compose.yaml up -d --build` — build and start app-php83,
  redis, nsq containers
- `composer app-code-quality` (alias `composer app-quality`) — run the full quality suite:
  phpcbf, phpcs, pdepend, phpmd, phpstan, psalm (plain + `--alter` + taint analysis), phan
- Syntax check: `php -l <file>`

## Code quality inside the container

Do not run the slow full multi-tool `composer app-code-quality` suite while iterating.
Run individual tools per changed file via `docker exec`, and finish with the full sweep.

Pipe a bash script to the container's stdin (repo is mounted at `/app`):

```bash
# Windows PowerShell: use a SINGLE-QUOTED here-string so $vars reach bash intact
$script = @'
cd /app
php -l src/Adapter/Beanstalk.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src/Adapter/Beanstalk.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Adapter/Beanstalk.php
'@
$script | docker exec -i app-php83 bash -s
```

or one-shot:

```bash
docker exec app-php83 bash -c "cd /app && php -l src/Adapter/Beanstalk.php"
```

Per changed file, run in order:

1. `php -l <file>` — syntax check
2. `phpcs --standard=build/phpcs-ruleset.xml --no-cache -s <file> --report=full` — code style
   (always pass `--no-cache`; otherwise stale `tmp/phpcs-tempfile` results are returned)
3. `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon <file>...` —
   static analysis (see the quirks below: the file set passed changes what PHPStan reports)
4. `psalm.phar --config build/psalm.xml --no-diff --show-info=true <file>...` — type checks
   (mirrors the `php-code-psalm-single-changed-file-check` pre-commit hook)

Full sweep before considering work done:

- `phpcs --standard=build/phpcs-ruleset.xml --no-cache src tests --report=full` — expect 0 errors
- `phpcbf --standard=build/phpcs-ruleset.xml --no-cache src tests` — expect
  **"No violations were found"** (idempotency check; any output means unfixed violations remain)
- `php build/check-classes.php` — expect "OK, every class file loads"
- `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon` — expect no errors
- `php ./vendor/bin/psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff
  --show-info=true --stats` — expect no errors
- `php ./vendor/bin/phpunit --configuration=phpunit.xml` — expect the full suite (see Testing quirks)
- `composer app-code-quality` — remaining tools (phan, phpmd, pdepend) in one pass

Notes:

- `tmp/` is excluded from the phpcs ruleset, so scratch/throwaway files can live there
  without breaking the sweep, but keep scratch PHP files out of `src/` and `tests/`.
- On Windows PowerShell, double-quoted script strings eat `$?`/`$f`/`$t` etc. Keep the
  script in a single-quoted here-string with pure bash inside.

## Testing quirks

- **Always run PHPUnit inside the container** — never on the host. The host environment
  (PHP version, missing extensions, different `vendor/` layout) will produce misleading
  results. Use `docker exec app-php83 sh -c 'cd /app && php ./vendor/bin/phpunit
  --configuration=phpunit.xml'` or `composer app-tests` from within the repo root.
- **The host shell is PowerShell, not bash.** Any command block that contains bash syntax
  (`$var`, backticks, `&&`, `|` piped to `docker exec -i … bash -s`) must be sent as a
  single-quoted here-string (`@' … '@`) so PowerShell does not interpolate variables.
  One-shot `docker exec … bash -c "…"` commands work too as long as no host `$` variables
  are present. Do not paste bare bash into a PowerShell prompt.
- Always run PHPUnit inside the container: `php ./vendor/bin/phpunit --configuration=phpunit.xml`
- `phpunit.xml` sets `failOnSkipped="true"` — any skipped test fails the run. The dockerized
  run never skips (redis + nsqd are up); a host-side run without reachable services fails
  rather than silently passing.
- `php -l` does **not** catch all load-time errors. A class file that fails to compile —
  e.g. a redundant union type such as `string|false|bool` ("Duplicate type false is
  redundant", fatal on PHP 8.2+), or a parameter type that narrows an inherited method
  ("Declaration of X::_read(?int $length) must be compatible with Y::_read($length)") — can
  make PHPUnit **die silently mid-run**: output stops, usually after a run of dots, with no
  summary and exit code 255. `php -l` still reports "No syntax errors". Run
  `php build/check-classes.php` (`composer app-classes`, or `composer app-classes-docker`
  inside the container) to link every class in an isolated child process and get the
  offending files with the fatal message. It runs as the pre-commit hook
  `php-code-class-load-check`, as a CI step before PHPUnit, and as the first entry of
  `app-code-quality`. The script skips procedural files without a class declaration, such as
  `tests/Support/FakeNsqdServer.php`.
- A PHPUnit run that stops at a dot with no summary is a load-time crash, not a normal test
  failure. The suite is healthy only when it prints the final summary (`OK` or
  `OK, but there were issues!`, exit 0).
- `--testdox` does not print test names while the suite runs. PHPUnit 10 buffers the whole
  testdox report and writes it after the run finishes, so the live output is the progress
  dots and a crashed run shows dots without names. Use `--debug` or `--log-junit` when
  per-test output is needed before the run ends.
- The `php-code-phpstan` pre-commit hook can report **"file(s) were modified by this hook"**
  with a `Failed` status even though its analysis output shows `[OK] No errors`. This is a
  benign false positive (pre-commit re-stages files it believes changed during the run).
  Ignore the hook status and judge the run by the analysis output: only a real
  `[ERROR]` findings block means the code is wrong.
- PHPStan's findings depend on which files are passed to it. The pre-commit hook passes only
  the **changed** PHP files, so analysing a child class can flag a parameter that narrows the
  signature it inherits (untyped parent `read($n)` vs child `read(int $n)` →
  `method.childParameterType`, non-ignorable). That narrowing is a hard PHP fatal ("must be
  compatible with"), not a style issue, so the child has to match the parent. If the parent
  lives in this repository, type the parent to match the child; if the parent is a vendor
  class — such as `vendor/davidpersson/beanstalk` for `src/Adapter/Beanstalk/Client.php` —
  remove the type from the child and keep it in the docblock. Psalm does not report this at
  all, which is why `build/check-classes.php` exists. A clean full-project run is not
  sufficient — also run PHPStan on the changed file alone to reproduce the hook's file set.
- `src/Message/Generic.php` intentionally implements **both** the `Serializable` interface
  and `__serialize()`/`__unserialize()` so queue payloads stay compatible with both old and
  new PHP serialization. Keep it as-is — with both paths present PHP 8.3 emits no
  deprecation; do not migrate it to one or the other.
- The Beanstalk tests (`tests/Adapter/BeanstalkAdapterTest.php` and
  `tests/Adapter/Beanstalk/ClientTest.php`) use an in-process fake server
  (`tests/Support/FakeBeanstalkServer.php`) and must pass without a real beanstalkd service.
- The Beanstalk tests assert **array key order**: the config `$defaults` in
  `src/Adapter/Beanstalk/Client.php` must stay in alphabetical order
  (`host, logger, persistent, port, timeout`) to match the test expectations.
- Three deprecations from the vendor `opis/closure` package are expected (`SerializableClosure
  implements Serializable`, dynamic property `ClosureStream::$context`). PHPUnit prints them
  as `D` and does not fail on them.
- `src/Adapter/Beanstalk.php` and `src/Adapter/Beanstalk/Client.php` carry a class-level
  `@phpcs:disable`; phpcs/phpcbf skip them entirely. Keep them correct via `php -l` and the
  tests instead — and remember redundancies in their types are still PHP fatals, not just
  style issues.
- `build/phpcs-ruleset.xml` intentionally excludes sniff combinations that make phpcbf crash
  or loop forever: `SlevomatCodingStandard.Attributes.AttributesOrder` requires the
  `orderAlphabetically=true` configuration, and `DisallowTrailingCommaInDeclaration` +
  `DisallowNonCapturingCatch` are excluded because they conflict with the matching "Require"
  sniffs. Do not re-enable them.
- `build/stubs/RedisManager.stub` is a Psalm stub for `Illuminate\Redis\RedisManager`
  (referenced from `build/psalm.xml`); keep it in sync if the illuminate/redis API changes.

## Conventions

- Follow the PSR-12 ruleset in `build/phpcs-ruleset.xml`; 4-space indentation, LF endings
- Prefer typed properties and parameters
- Do not add comments unless they add real value; keep code self-documenting
- Do not commit `vendor/`, `composer.lock`, or secrets
- New public API must be accompanied by an entry in `UPGRADING`

## Verification

- After changing code run `php -l` on touched files and the relevant code-quality tool(s)
  (phpcs/phpstan/psalm) per the "Code quality inside the container" section, then the full
  sweep, before considering work done
