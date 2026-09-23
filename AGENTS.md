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
    `Redis`, `Nsq`, `DynamoSQS`, `Beanstalk`
  - `src/Worker/` — job workers. Extend `AbstractWorker`, implement `run(): void`
  - `src/Publisher/` — job publishers. Extend `AbstractPublisher`, implement `setupAdapter()`
  - `src/Message/` — job payloads implementing `ConsumeInterface`
- `example/` — runnable publisher/worker examples (not shipped)
- `build/` — code-quality configuration: `psalm.xml`, `phpstan.neon`, `phpcs-ruleset.xml`,
  `phan.php`, `phpmd-rulesets.xml`, Dockerfiles
- `plans/` — design/modernization plans to be implemented in future changes

## Environment

- PHP >= 8.3 (see `plans/php-8.3-and-repository-review-improvement-plan.md`, the current
  top-level modernization plan; `plans/php-8.1-modernization.md` records the 4.0 work);
  `vendor/` and `composer.lock` are gitignored; install and verify via composer
- Changes are made on dedicated branches and land as pull requests
- Integration tests for the `Redis` and `Nsq` adapters (`tests/Adapter/RedisAdapterTest.php`,
  `tests/Adapter/NsqAdapterTest.php`) require running services; they are exercised via the
  dockerized app in `build/` and skip themselves when the services are unreachable
- CI: `.github/workflows/ci.yml` builds the php83 dockerized app and runs
  `app-tests` + `app-code-quality` on every PR and merge to master
- All lint, code-quality, static-analysis and test commands must run **inside** the
  `backq.php83` docker container (PHP 8.3, repo mounted at `/app`, `vendor/` installed,
  `redis` + `nsq` services healthy). Host PHP must not be used for these checks.

## Commands

- `composer install` — install dependencies
- `composer app-tests` — run the PHPUnit suite inside the dockerized app (`build/Dockerfile.php83`)
  with `redis` + `nsq` services from `build/docker-compose.yaml`; requires Docker
- `composer app-tests-local` — run the PHPUnit suite on the host (Redis/Nsq integration
  tests skip without the services)
- `docker compose -f build/docker-compose.yaml up -d --build` — build and start app-php83,
  redis, nsq containers
- `composer app-code-quality` — run the full quality suite (phpcs, phpcbf, phpstan, psalm,
  phan, phpmd, pdepend)
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
$script | docker exec -i backq.php83 bash -s
```

or one-shot:

```bash
docker exec backq.php83 bash -c "cd /app && php -l src/Adapter/Beanstalk.php"
```

Per changed file, run in order:

1. `php -l <file>` — syntax check
2. `phpcs --standard=build/phpcs-ruleset.xml --no-cache -s <file> --report=full` — code style
   (always pass `--no-cache`; otherwise stale `tmp/phpcs-tempfile` results are returned)
3. `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon <file>...` —
   static analysis (see the quirks below: the file set passed changes what PHPStan reports)

Full sweep before considering work done:

- `phpcs --standard=build/phpcs-ruleset.xml --no-cache src tests --report=full` — expect 0 errors
- `phpcbf --standard=build/phpcs-ruleset.xml --no-cache src tests` — expect
  **"No violations were found"** (idempotency check; any output means unfixed violations remain)
- `phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon` — expect no errors
- `php ./vendor/bin/phpunit --configuration=phpunit.xml` — expect the full suite (see Testing quirks)

Notes:

- `tmp/` is excluded from the phpcs ruleset, so scratch/throwaway files can live there
  without breaking the sweep, but keep scratch PHP files out of `src/` and `tests/`.
- On Windows PowerShell, double-quoted script strings eat `$?`/`$f`/`$t` etc. Keep the
  script in a single-quoted here-string with pure bash inside.

## Testing quirks

- Always run PHPUnit inside the container: `php ./vendor/bin/phpunit --configuration=phpunit.xml`
- `php -l` does **not** catch all load-time errors. A class file that fails to compile —
  e.g. a redundant union type such as `string|false|bool` ("Duplicate type false is
  redundant", fatal on PHP 8.2+) — can make PHPUnit **die silently mid-run**: output stops,
  usually after a run of dots, with no summary and exit code 255. `php -l` still reports
  "No syntax errors". Detect such files by force-loading every class, e.g. loop over
  `src/` and `tests/` calling `class_exists()` (skip `tests/Support/FakeNsqdServer.php` —
  it is a procedural CLI script, not a class).
- A PHPUnit run that stops at a dot with no summary is a load-time crash, not a normal test
  failure. The suite is healthy only when it prints the final summary (`OK` or
  `OK, but there were issues!`, exit 0).
- The `php-code-phpstan` pre-commit hook can report **"file(s) were modified by this hook"**
  with a `Failed` status even though its analysis output shows `[OK] No errors`. This is a
  benign false positive (pre-commit re-stages files it believes changed during the run).
  Ignore the hook status and judge the run by the analysis output: only a real
  `[ERROR]` findings block means the code is wrong.
- PHPStan's findings depend on which files are passed to it. The pre-commit hook passes only
  the **changed** PHP files, so analyzing a child class without its parent file in the set
  flags child-parameter narrowing that PHP itself allows (untyped parent param `read($n)` vs
  child `read(int $n)` → `method.childParameterType`, non-ignorable). When a child's narrow
  parameter is flagged, type the parent's signature to match the child (the parent is the
  contract). A clean full-project run is not sufficient — also run PHPStan on the changed
  file alone to reproduce the hook's file set.
- `src/Message/Generic.php` intentionally implements **both** the `Serializable` interface
  and `__serialize()`/`__unserialize()` so queue payloads stay compatible with both old and
  new PHP serialization. Keep it as-is — with both paths present PHP 8.3 emits no
  deprecation; do not migrate it to one or the other.
- `tests/Adapter/Beanstalk/ClientTest.php` uses an in-process fake server
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

## Conventions

- Follow the PSR-12 ruleset in `build/phpcs-ruleset.xml`; 4-space indentation, LF endings
- Prefer typed properties and parameters
- Do not add comments unless they add real value; keep code self-documenting
- Do not commit `vendor/`, `composer.lock`, or secrets
- New public API must be accompanied by an entry in `UPGRADING`

## Verification

- After changing code run `php -l` on touched files and the relevant code-quality tool(s)
  (phpcs/phpstan) per the "Code quality inside the container" section, then the full sweep,
  before considering work done
