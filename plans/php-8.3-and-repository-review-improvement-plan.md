# BackQ Repository Review & Improvement Plan (aligned with the PHP 8.3 upgrade)

- Status: draft — review complete (every tracked file inspected, Sep 2026)
- Target: `php >= 8.3` (planned upgrade happens at the same time as this work)
- Owns: follows the 4.0 modernization work (`plans/php-8.1-modernization.md`,
  records 1A–2D); this document is the next top-level plan
- Related: `AGENTS.md`, `composer.json`, `build/`, `tests/`, `example/`, `UPGRADING`

---

## 1. What was reviewed

Full inventory of tracked files (vendor/ and build/tmp caches ignored). Every file was
read and assessed for **purpose**, **outdatedness** and **improvement potential**.

### 1.1 Root / meta files

| File | Purpose | Verdict |
|---|---|---|
| `AGENTS.md` | Agent/contributor guidance | Current. Add this plan to the "Environment" note once the 8.3 floor lands. |
| `composer.json` | Package manifest | See §2.2 — several packaging issues (undeclared `symfony/console`, `minimum-stability: dev`, platform pin 8.1). |
| `composer.lock` | Lockfile | Gitignored by design (library). Content reveals dev-quality resolutions (see §2.2). |
| `phpunit.xml` | PHPUnit 10.5 config | OK. No coverage report/`@covers`, `failOnWarning` is good. |
| `README.md` | User docs | OK; worker/adapter feature tables mark `hasWorkers`/`ping` caveats correctly. Update PHP floor + install tag when 5.0 ships. |
| `UPGRADING` | Changelog | Current through 4.0. Add a 5.0 section per this plan. |
| `.gitignore` / `.gitattributes` / `.editorconfig` | Meta | Fine (`.gitattributes` pins `eol=lf` for `*.php`). |
| `.github/workflows/opencode.yml` | PR-comment bot | Working; pinned `actions/checkout@v6`. No CI test/quality workflow exists. **Improvement:** add a GitHub Actions CI job for `app-tests` + `app-code-quality` so the dockerized suite runs on every PR. |
| `.env` | Compose env | Fine, no secrets. |
| `CODE_OF_CONDUCT`, `DCO`, `MAINTAINERS`, `LICENSE` | Legal/social | Fine; LICENCE.txt duplicates LICENSE (tracked → see §5). |

### 1.2 Core abstractions (`src/`)
All purpose-stable; improvement potential is type-hardening + dead-code cleanup:

- `Adapter/AbstractAdapter.php` — contract. Untyped params (`$queue`, `$workId`, `$body`,
  `$params`) and `pickTask`/`putTask` unions stay loose. `logError()` default
  `$triggerErrorOnError = true` fires `E_USER_WARNING` from library code paths that are
  routine (e.g. DynamoSQS on an invalid SQS body) — consider defaulting to `false` and
  letting consumers opt in.
- `Worker/AbstractWorker.php` — generator worker loop. `$workTimeout` was demoted to
  untyped (`:37`); on a 5.0 floor it can become `?int` if children are updated too
  (property types are invariant — children `Closure`, `Serialized`, SNS workers currently
  declare `public $workTimeout = 5` and must match). `debug()` is a `@deprecated` alias
  (`:159`) — remove in 5.0. `start()` uses `pcntl` guarded by `function_exists`; good.
- `Publisher/AbstractPublisher.php` — `__sleep`/`__wakeup` drop the adapter and rebuild it
  via a fresh `setupAdapter()` (`:151-154`); this is a documented foot-gun for the
  Serialized flow (constructor config is lost). `getInstance()` factory is untested.
- `Message/AbstractMessage.php`, `Message/ConsumeInterface.php` — fine.
- `Logger.php` — legacy file logger (`$logFile`+`fopen`), predates PSR-3; no longer used by
  the worker stack (workers use `ConsoleLogger`). **Improvement:** delete in 5.0 or
  re-implement as a small PSR-3 `LoggerInterface`.

### 1.3 Adapters (`src/Adapter/`)
- `Redis.php` — largest behavioral surface. `connect()` is a no-op stub (`:544-554`: sets a
  flag; real connection happens lazily in `_connect()` during `bind*`) — misleading for
  consumers calling `connect()`+`ping()`. `hasWorkers()` returns `true` under `?int`
  (`:376-379`) — contract lie, coerces to `1`. `setWorkTimeout()` clamps against
  `read_timeout`; `BLOCKFOR_EMULATE` branch (`:413-433`) sleeps 1s granularity. Reserved-job
  release on disconnect (`:207-215`) is good. `retryJobAfter` +
  `:notify` blocking-pop caveats are documented. **Improvement:** unit-test the timeout
  clamp, release path, delayed `putTask`, afterWork* job-id-mismatch exception; make
  `connect()` actually open the connection or state that it is lazy.
- `Redis/Queue.php`, `Redis/Connector.php`, `Redis/App.php` — thin illuminate shims;
  correct. `App` exists solely to override `isDownForMaintenance()`.
- `Beanstalk.php` — solid. `pickTasks()` (`:222`) is dead API. `hasWorkers()` is real.
  `pickTask()` ignores its `$timeout` param (uses `$this->workTimeout` instead) — worth
  documenting or honoring the parameter.
- `Beanstalk/Client.php` — protocol client with vendored `davidpersson/beanstalk` parent.
  `reserve()` with `$timeout=null` sets a `PHP_INT_MAX` stream timeout (`:107`) — infinite
  block risk, documented as such. Wire code was verified against the protocol by tests.
- `Nsq.php` — **heartbeat bug** (see §2.1): `pickTask()` returns `['','',[]]` for heartbeat
  frames (`:307-315`), which the worker treats as a job; ack on empty id fails →
  `afterWorkFailed` (`Nsq::afterWorkSuccess('')` returns `false`, `:227`) → worker throws.
  Return `false` instead. `hasWorkers()` deprecated stub → `true` under `?int`. Protocol
  constants are `final` (good). `writeIdentify()`/`readFrame()` are the only untested
  real-wire paths.
- `DynamoSQS.php` — purpose-specific (long-delay scheduling). `ping()` always `true`
  (`:280`), `hasWorkers()` stub `true`. `pickTask()` uses `@json_decode` and calls
  `logError` on malformed bodies — fine but triggers `E_USER_WARNING`. `afterWorkFailed`
  deliberately no-op (`:271`). `putTask` id is `crc32(pid.host)` (weak uniqueness; fine).
  `disconnect()` destroys clients. **Improvement:** implement `hasWorkers` via SQS
  `GetQueueAttributes` (ApproximateNumberOfMessages) or document; per the AWS testing plan
  the offline tests already cover the request shapes.
- `IO/AbstractIO.php`, `IO/StreamIO.php` — low-level blocking/non-blocking socket layer.
  `write()` juggles `error_reporting`/custom handler per call (`:250-302`) — performance +
  correctness smell, but works. `selectRead/selectWrite`, `isSocketReady` are only partially
  test-covered (tests use `addToAssertionCount(1)` no-ops).
- `Amazon/DynamoDb/QueueTableRow.php` — `fromArray()` reads `$metadata['payload_checksum']`
  without checking the key exists (`:52`) — undefined-index warning on hand-crafted rows.
  Strong `readonly` candidate (fully populated in constructor).

### 1.4 SNS stack (`src/Worker|Publisher|Message/Amazon/SNS`)
- `SnsClient.php` — pure passthrough; could be deleted in favor of `Aws\Sns\SnsClient`.
- `Application.php` / `Application/PlatformEndpoint.php` — small base classes; queue name is
  derived from class name (`_register_`/`_publish_`/`_remove_` + platform suffix).
- `Register|Remove|Publish` workers — **reversed `is_subclass_of()`** in all three
  (see §2.1) — the `NetworkException` branches are dead. Also **duplicate-retry logic** in
  `Remove`/`Publish`/`Register` (same `$reprocessedTasks` block ×3) — extract a helper or
  trait. `Publish::run` has a **double-ack `finally`** (see §2.1).
- SNS messages & interfaces — getters like `Publish::getAttributes()`/`getMessageStructure()`
  return typed values from unset properties (**uninitialized-property fatal risk** in the
  worker if a queue payload was built with setters only); `Register`/`Remove`/`Publish`
  should either initialize properties (`= null` + nullable) or use constructor promotion.
- SNS publishers — abstract shells; fine.

### 1.5 Messages / Publishers / Workers
- `Message/Guzzle.php` — `$request->withRequestTarget('absolute-form')` is a **no-op on the
  frozen request** (`:34`); it only works because `getRequest()` re-applies `$this->scheme`.
  Cache the parsed request to avoid parsing on every call.
- `Message/Generic.php` — implements the **`Serializable` interface, deprecated since PHP 8.1**
  → `E_DEPRECATED` on PHP 8.3 when serialized. Migrate to `__serialize()`/`__unserialize()`.
- `Message/Closure.php` — depends on `opis/closure` 3.x `SerializableClosure` constructor
  (blocking the 4.x upgrade; documented). Security: queued closures execute arbitrary code —
  keep restricted to trusted publishers; worth an advisory note in README.
- `Message/Process.php`, `Message/Serialized.php` — fine.
- `Worker/Closure.php`, `Worker/Guzzle.php`, `Worker/AProcess.php`, `Worker/Serialized.php` —
  all use the `@unserialize` + instanceof pattern (consistent). `Guzzle` worker logs
  **"Error while sending FCM"** (`:104`, leftover from pre-4.0) and always reports success
  (`$processed=true`); `AProcess` always `$processed=true` so it never surfaces failed
  launches and silently drops past-deadline jobs as success — add explicit drop/retry
  configuration. `AProcess` string commandline path (`:131`) is deprecated Shell verbatim —
  keep but document, prefer arrays. `Closure` worker's `RecoverableException` retry branch is
  never tested by a worker-level test.

### 1.6 Tests (`tests/`) — see also `plans/testing-aws-sns-dynamodb.md`
Strong suite (~170 tests) after the 4.0 work. Gaps, by value:

1. `Redis` adapter: only a thin docker smoke test exists — the timeout-clamp, reserved-job
   release, delayed put and afterWork* mismatch logic have **zero unit coverage**.
2. SNS worker error classification: tests throw the **AWS base** `SnsException`, not the
   BackQ subclass, so the reversed `is_subclass_of` never surfaces (the bug is masked) and
   `RETRY_MAX`/`onFailure`/double-ack paths are untested.
3. `NsqAdapterCoreTest` frame/identify tests are **tautological** (assert the test's own
   builder helpers) — real frame parsing is untested.
4. `StreamIOTest` two tests are `addToAssertionCount(1)` no-ops; `READ_TIME_CODE`, write-EOF
   and `isSocketReady` untested.
5. `AbstractWorkerTest` lacks idle-timeout / restart-threshold / signal paths; `GuzzleWorker`
   and `AProcess` real behavior untested; `ClosureWorker` `RecoverableException` branch
   untested; `SerializedWorker` `__PHP_Incomplete_Class` publisher guard untested.
6. `PublishMessageTest` etc. don't exercise unset-property getters (the 1.4 fatal risk).
7. `BeanstalkAdapterTest::testConnectFailureReturnsFalseWhenPeerIsDown` closes and
   immediately rebinds a port — flaky under load.
8. `TestPublisher` uses a static shared adapter (parallel-test hazard); `TestWorker` is fine.
9. `tests/Support/*` untyped props could be typed to catch contract drift.

### 1.7 Examples (`example/`)
- **Duplicate class** `MyProcessPublisher` is defined in three files
  (`publishers/process.php`, `publishers/process/redis.php`,
  `publishers/lib/myprocesspublisher.php`) with different queues — confusing and a
  duplicate-symbol problem for any analysis that includes `example/`.
- **Queue-name fragmentation** breaks the runnable story: `abc` (process pair), `process`
  (redis pair), `123` (serialized pair), `456` (lib publisher — **no worker example**), so
  the Serialized demo strands its delayed job.
- SNS endpoint examples: `class endpoints` (**PSR-1 violation**, `endpoints.php`);
  `chdir(__DIR__)` + relative include; `$auth = []` placeholder (AWS SDK will throw);
  per-platform `apns|apns_sandbox|baidu|gcm` entries are **git symlinks** that materialize as
  plain text (`../register.php`) on Windows — running them does nothing.
- `adapter/nsq/pop.php` treats the heartbeat/empty-id `pickTask()` result as a real job
  (see §2.1) — must guard `if ($job && $job[0])`.
- `adapter/dynamosqs/`: `stream_process.js` (Node 8.10, **EOL**, env-var names that don't
  match `environment.txt`), `backq-scheduled-stream.js` (Node 12, **EOL**),
  two **byte-identical** policy JSONs, `environment.txt` brace-placeholders.
- `example.xml` is an **orphan** (draw.io source; nothing references it); `example.jpg` is
  referenced by README and is fine.
- Examples are excluded from phpcs (`build/phpcs-ruleset.xml:20`) and phpstan
  (`build/phpstan.neon` `excludePaths`) — they drift silently; re-enable or add `php -l`.

### 1.8 Build & CI (`build/`)
- `Dockerfile.php81` — self-contained after 2D; **bump to PHP 8.3** (`FROM php:8.3-cli`).
  Volume-mounts the repo; installs `ext-redis`, `ast`, `pcntl`, mysqli, composer, pre-commit,
  phpcbf harness. Ok.
- `docker-compose.yaml` — `redis` + `nsq` services with healthchecks (good); hardcoded
  `platform: linux/amd64` (works on most hosts but not Apple Silicon semantics); ports are
  host-bound on 127.0.0.1. Add `localstack` when the AWS integration plan (Phase 2) lands.
- `php.ini` — `error_reporting=E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT` **hides
  `E_DEPRECATED`** — this is why `Serializable`/implicit-nullable deprecations go unnoticed
  on PHP 8.3; enable `E_DEPRECATED` in a dedicated 8.3 build to catch them. `opcache.jit=0202`
  is the old "tracing" value — set a modern `tracing`/`function` profile for 8.3.
- `phpcs-ruleset.xml` — PSR-12 + slevomat; **excludes** `example/`; warning-severity=0;
  heavy exclusion list (TODO-marked) to trim. Adds `DisallowMixedTypeHint` → effectively
  forbids `mixed`.
- `phpstan.neon` — **level 0**; the 4.0 plan probed levels 1–7 (E2–E262). Raise stepwise to
  ≥5 after the 8.3 typing work. `paths` ok. `excludePaths: ../example/*`.
- `psalm.xml` — `phpVersion="8.1"`, `errorLevel=3`; `<extraFiles><directory name="../test"/>`
  points at a **non-existent `../test`** (should be `../tests`). The `psalm.phar` runtime
  constraint (`~8.1.31||~8.2.27||~8.3.16||~8.4.3`) must be validated on the 8.3 docker image.
- `phan.php` — `target_php_version='8.1'`; `minimum_target_php_version` obsolete (7.4);
  requires `ext-ast`; runs only in docker. Update both versions to 8.3.
- `phpmd-rulesets.xml`, `pdepend.xml` — reasonable; pdepend produces SVG/XML artifacts in
  `build/tmp` with no gate.
- `phpdoc.xml` — no composer script invokes it (docker image downloads the phar but nothing
  calls it); surface as a script or drop.
- `.pre-commit-config.yaml` — phpcbf/phpcs/phpstan/psalm hooks over all `*.php`
  (including `example/` when run from repo root — inconsistent with the ruleset excludes).
- `build/README.md` — references a `badges`/`pages` branch scheme; fine.

### 1.9 Plans
- `plans/php-8.1-modernization.md` — status/history doc for 4.0. Records are thorough; its
  acceptance criteria and deferred items (enums, readonly, `match`, phpstan level) roll into
  this plan.
- `plans/testing-aws-sns-dynamodb.md` — AWS test plan; Phase 1 (offline tests) shipped, Phase
  2 (LocalStack) open; its §3.2 design is reused below.

---

## 2. Confirmed defects (fix first — these are bugs, not style)

### 2.1 Correctness bugs
1. **NSQ heartbeat breaks the worker loop** — `Nsq::pickTask()` returns `['','',[]]` for a
   heartbeat frame (`src/Adapter/Nsq.php:307-315`); the generator treats it as a job,
   acks with id `''`, `Nsq::afterWorkSuccess('')` returns `false` (`:227`) and the worker
   throws `Worker failed to acknowledge job result`. Every heartbeat restarts the worker.
   Fix: return `false` on heartbeat (and guard in `example/adapter/nsq/pop.php`).
2. **SNS worker `is_subclass_of()` argument order is reversed** in Register (`:78-81`,
   `:108-110`), Remove (`:86-89`, `:117-119`) and Publish (`:91-94`, `:122-124`).
   `is_subclass_of($child, $parent)` is called with the BackQ class as object/class arg and
   `$e::class` as the parent — it only passes for `SnsException` because BackQ's class
   happens to extend AWS's. The `NetworkException` branch checks `is_subclass_of(BackQ\…
   NetworkException, $e->getPrevious()::class)` where the previous is a Guzzle exception —
   **always false, dead code**. Rewrite with `$e instanceof SnsException` /
   `$e->getPrevious() instanceof NetworkException`.
3. **SNS `Publish` worker double-ack** — `src/Worker/…/Publish.php:72-161`: the `finally`
   block `$work->send(true)` (`:159-161`) runs even after `$work->send(false); continue;`
   inside `catch` — an internal/network retry both fails **and** acks the job. Remove the
   `finally`; send exactly once per branch.
4. **`Message\Guzzle::__construct` no-op** — `$request->withRequestTarget('absolute-form')`
   (`src/Message/Guzzle.php:34`) is discarded; it only "works" by luck of scheme re-application
   in `getRequest()`. Either use the returned request or drop the call.
5. **`Message\Generic` uses the PHP 8.1-deprecated `Serializable` interface**
   (`src/Message/Generic.php:13`) → deprecation on PHP 8.3. Migrate to
   `__serialize()`/`__unserialize()`.
6. **`QueueTableRow::fromArray()` undefined-index on `metadata['payload_checksum']`**
   (`src/Adapter/Amazon/DynamoDb/QueueTableRow.php:52`) for hand-crafted SQS bodies.
7. **SNS message getters can hit uninitialized typed properties** — e.g.
   `Publish::getAttributes()`/`getMessageStructure()` (`src/Message/…/Publish.php:80,95`)
   and `Register`/`Remove` equivalents return typed values that were never set (drawn from a
   serialized worker payload built with only `set*` calls) → `Error: Typed property must not
   be accessed before initialization`. Initialize (`?bool`/defaults) or promote.
8. **`Redis::hasWorkers()`/`Nsq::hasWorkers()`/`DynamoSQS::hasWorkers()` return `true` under
   `?int`/`bool`** (`Redis.php:376`, `Nsq.php:276`, `DynamoSQS.php:288`) — a contract lie
   that coerces to `1` only in weak mode; type them `bool` and return real values (SQS
   `ApproximateNumberOfMessages`) or document the stub.
9. **`connect()` on Redis is a no-op** (`Redis.php:544-554`) — it only flips a flag; the
   real open happens during `bind*`. Either open eagerly (and surface failures from
   `connect()`) or document laziness.

### 2.2 Packaging issues (`composer.json` / lock)
1. **`symfony/console` is used directly** (`src/Worker/AbstractWorker.php:16-17` —
   `ConsoleLogger`/`ConsoleOutput`) but is **not in `require`**; it resolves only because
   `illuminate/console` pulls it. Declare `^6.4 | ^7.0` explicitly.
2. **`minimum-stability: "dev"`** with the `php: 8.1` platform pin resolves dev versions in
   the lock (`illuminate/* 10.x-dev`, `guzzlehttp/psr7 2.13.x-dev`, `davidpersson/beanstalk
   2.1.x-dev`, `opis/closure 3.x-dev`, symfony components `dev-main`…). A library should not
   ship expecting dev stability — move dev-only deps to require-dev, prefer stable tags, and
   only fall back to `minimum-stability` flags where forced.
3. **Platform pin `"php": "8.1"`** gates all resolution at 8.1 semantics; raise to `8.3`
   with the upgrade and regenerate the lock, then re-run the security audit (the 4.0 work
   already cleared 15 advisories).
4. `phpunit` floor `^10.5` — PHPUnit 10.x supports PHP 8.3; consider `^11` once CI is on 8.3.
5. GitHub CI: add a workflow matrix (8.3 docker image) running `composer app-code-quality`
   + `composer app-tests`, replacing the manual/bot-only setup.

---

## 3. PHP 8.3 upgrade package (the "at the same time" work)

### 3.1 Floor & tooling
- `composer.json`: `"php": ">=8.3"` (semver-major → 5.x), `platform.php = 8.3`,
  `minimum-stability` cleanup, add `symfony/console`.
- `build/Dockerfile.php81` → `build/Dockerfile.php83` (`php:8.3-cli`); update compose service
  name `app.php83`, `.env`, `composer.json` `app-tests`/`app-code-quality` references,
  `AGENTS.md`, `README.md`, `UPGRADING`.
- Static analysis on 8.3: `build/psalm.xml` `phpVersion=8.3` (+ fix `../test` →
  `../tests`), `build/phan.php` `target_php_version=8.3` (drop `minimum_target_php_version`),
  raise `phpstan.neon` from `level: 0` stepwise toward ≥ 5 (re-record the probe), enable
  `E_DEPRECATED` in `build/php.ini` (catch deprecations the 8.3 build would otherwise hide).
- Add a GitHub Actions workflow running the dockerized suite on every PR/merge to master.

### 3.2 Language features now available (adopt deliberately, record in UPGRADING)
- `#[Override]` (8.3): add to adapter/worker/publisher/message method overrides — cheap and
  would have caught the SNS subclass misuse.
- `readonly` (8.2): convert value objects — `QueueTableRow` (after the checksum guard fix),
  `Message\Process`, SNS message DTOs. Requires `__serialize/__unserialize` before touching
  payload classes that cross adapters.
- **Enums**: `BackQ\Adapter\ConnectionState` for the Redis/Nsq `STATE_*` machine (internal
  only; keep integer constants for BC) — deferred from 4.0.
- `json_validate()` (8.3): replace `@json_decode(...)` + `is_array` checks in `Nsq.php`,
  `DynamoSQS.php`, `QueueTableRow.php`.
- `str_increment`/`mb_str_pad`/`array_*` niceties: opportunistic only.
- Typed class constants are 8.3 — already `final`; convert the remaining `public const` in
  adapters where sensible.
- **`Serializable` migration**: `Message\Generic` → `__serialize()/__unserialize()`.

### 3.3 Contract tightening (5.0 BC changes — record each in UPGRADING)
- Type `AbstractAdapter` params (`$queue: string`, `$workId: string|int`, `$body: mixed`,
  `$params: array`); keep the union returns.
- Decision: `AbstractWorker::$workTimeout` → `?int` on the parent **and** children
  (`Closure`, `Serialized`, SNS workers declare `= 5` → `?int $workTimeout = 5`).
- Remove deprecated `Worker::debug()`, `Nsq::info()/error()` aliases, dead `Beanstalk::pickTasks()`.
- `hasWorkers()` typed `bool` (all adapters) with real semantics or explicit stub contract.

---

## 4. Implementation roadmap (future changes, in order)

### Phase A — Bug-fix & hardening (no API break; land first)
1. Fix §2.1 items 1–4, 6, 7 (NSQ heartbeat, `instanceof` rewrites, Publish double-ack,
   Guzzle no-op, checksum guard, SNS getter init).
2. Add unit tests that **fail before / pass after** each fix (throw the BackQ SNS exception
   subtypes; drive real NSQ frames; SNS message getter pre-state; Redis adapter unit suite).
3. `example/` hygiene: de-duplicate `MyProcessPublisher`, wire queues so every publisher has
   a matching worker, guard `nsq/pop.php`, rename `endpoints` → `Endpoints`, `__DIR__`-based
   includes, env-driven AWS auth, replace symlink platform entries with a single
   `register|publish|remove.php <platform>` script, delete `example.xml` + duplicate policy
   JSON, modernize/consolidate the two DynamoDB Lambda scripts (Node ≥ 18, SDK v3) and
   reconcile `environment.txt`/env-var names.

### Phase B — PHP 8.3 upgrade (at the same time)
1. `composer.json`/lock, Dockerfile.php83, compose, `.env`, php.ini (`E_DEPRECATED`, JIT),
   tooling versions; run the full suite on 8.3 in docker **before** feature work.
2. Address whatever `E_DEPRECATED` surfaces (implicit nullable already cleared in 4.0;
   `Serializable` remains).
3. Add `symfony/console` to `require`; clean up `minimum-stability`.

### Phase C — PHP 8.3 modernization refactors (5.0)
1. `#[Override]` sweep; `__serialize/__unserialize` on payload classes; `readonly` where
   safe; `ConnectionState` enum; `json_validate()`; `AbstractWorker::$workTimeout` typing;
   remove deprecated aliases/dead API; contract param typing.
2. Static analysis escalation: phpstan ≤5→7 stepwise, psalm errorLevel 3→2, re-enable
   `example/` in phpcs/phpstan (or move examples to a linted `tests`-style surface).

### Phase D — Test depth & AWS integration
1. Redis adapter unit suite; NSQ real-frame tests; `AProcess`/`GuzzleWorker` behavior tests;
   `AbstractWorker` idle/signal/restart branches; replace StreamIO no-op tests; de-flake the
   Beanstalk port-reuse test; interface-conformance sweep (every message type implements `ConsumeInterface`).
2. `plans/testing-aws-sns-dynamodb.md` Phase 2 — LocalStack in compose, SQS/DynamoDB/SNS
   integration tests, `AWS_ENDPOINT_URL*` wiring.
3. Re-enable `@covers` + coverage report (test gap machine-checkable) once PHPUnit 11 lands.

### Phase E — Docs & release
1. `UPGRADING` 5.0 section (all BC breaks + new features). `README.md` floor + install tag.
   `AGENTS.md` Environment update. Trim LICENCE.txt/LICENCE duplicates. Add CI workflow.
2. Release: tag 5.0 after `composer app-code-quality` + `composer app-tests` green on 8.3.

---

## 5. Risk register

| Risk | Mitigation |
|---|---|
| SNS `instanceof` rewrite changes retry behavior | cover with tests that throw the BackQ subtypes before merging |
| Raising phpstan level floods unresolved legacy `mixed` | stepwise PRs (record the probe like 4.0 did) |
| `readonly`/`__serialize` changes wire format of queue payloads | only apply to classes that never cross an adapter serialized 1:1; keep wire bytes identical |
| Docker-only tools (phan, psalm) can't run on host PHP 8.5 | validate in the 8.3 container; keep `app-*` scripts dockerized |
| Dev-stability resolution after `minimum-stability` cleanup | pin explicit dev deps that genuinely need it; re-run audit |
| Windows symlink example scripts break | replace with parameterized single scripts (Phase A) |

## 6. Out of scope

- Async/fiber rewrite of the worker loop.
- Replacing the `illuminate/queue`-borrowed Redis queue internals.
- Real-AWS end-to-end `DynamoDB Streams → Lambda → SQS` testing (manual runbook only).
- Supporting PHP < 8.3 after the upgrade.