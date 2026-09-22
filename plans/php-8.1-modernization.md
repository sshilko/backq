# PHP 8.1 Modernization Plan

- Status: implemented in phases (see 1A, 2A-2D); docs done (Phase 6);
  enums/readonly/`match`/phpstan-level remain open (deferred, see records)
- Target: `php >= 8.1` (current `composer.json`)
- Owns: this plan lives in `plans/` and is implemented in follow-up change(s)
- Related: `AGENTS.md`, `build/psalm.xml`, `build/phpstan.neon`

## 1. Goal

Raise the minimum supported PHP version to 8.1 and adopt PHP 8.0/8.1 language features
without breaking the external API any more than a semver-major release already allows.
Deliverable is a merged PR (v4) that keeps `composer app-code-quality` green on PHP 8.1+.

## 2. Current state assessment

~7.1k lines across 60 files in `src/`, PSR-4 namespace `BackQ\`. Partially typed:
typed properties coexist with ~67 untyped properties, abstract methods have `: void`
return types that contradict concrete implementations (see 4.1), and no PHP 8 syntax
is in use yet.

Findings, verified against the current tree:

| # | Finding | Location |
|---|---|---|
| 1 | `public int $workTimeout = null;` — implicit nullable; deprecated in 8.1, **fatal on PHP 8.3** (verified via `php -l`) | `src/Worker/AbstractWorker.php:37` |
| 2 | Abstract methods declared `: void` while every concrete adapter omits the return type and returns `bool`/`array`/`string`/`false`. This fails PHP's signature-compatibility check when a concrete adapter class is loaded (verified fatal). PHPStan currently papers over it with `#not covariant#` | `src/Adapter/AbstractAdapter.php:34-88`, all adapters (`Redis`, `Beanstalk`, `Nsq`, `DynamoSQS`) |
| 3 | `hasWorkers(): ?int` always `return true;` — type error when invoked | `src/Adapter/Redis.php:399` |
| 4 | Constructor assigns parameters to identical typed properties | `src/Adapter/Redis.php:121-168`, `src/Adapter/Amazon/DynamoDb/QueueTableRow.php:31`, `src/Message/Process.php:16-24`, `src/Adapter/IO/StreamIO.php:67-85` |
| 5 | `if ($this->logger) { $this->logger->info(...) }` guards | `src/Adapter/AbstractAdapter.php:109-138`, `src/Worker/AbstractWorker.php:145-186` |
| 6 | Value-object that is fully populated in constructor and never mutated afterwards — `readonly` candidate | `src/Adapter/Amazon/DynamoDb/QueueTableRow.php:18-76` |
| 7 | Internal integer "state machine" constants | `src/Adapter/Redis.php:38-46`, `src/Adapter/Nsq.php:73-75` |
| 8 | `false !== strpos(...)` | `src/Logger.php:35` |
| 9 | `get_class($e)` in log/diagnostics | `src/Worker/Amazon/SNS/Application/PlatformEndpoint/{Register,Remove,Publish}.php` |
| 10 | Docblock annotations used for tooling metadata | `src/Adapter/Redis.php:34` (`@SuppressWarnings(PHPMD...)`), `@var` annotations across `src/` |
| 11 | Tooling still targets 7.4 / level 0 | `build/psalm.xml:13`, `build/phpstan.neon:9` |
| 12 | PHP 7.4/8.0 docker images still shipped | `build/Dockerfile.php74`, `build/Dockerfile.php80` *(deleted; only `Dockerfile.php81` remains)* |
| 13 | Dependency floor predates PHP 8.1 support guarantees | see 4.3 |

## 3. Compatibility & versioning strategy

- This is a semver-**major** release (3.x → 4.x).
- Adapters, workers, publishers and messages remain source-compatible where practical;
  the unavoidable breaks are: (a) added/restored method return types on the adapter
  contract, and (b) dropped PHP < 8.1.
- Queue payload wire format stays byte-compatible: keep `serialize()`/`json` encodings,
  DynamoDB row layout and SQS message shape unchanged (see `src/Message/Serialized.php`,
  `src/Adapter/DynamoSQS.php`, `src/Adapter/Amazon/DynamoDb/QueueTableRow.php`).
- Every public API change is recorded in `UPGRADING`.

## 4. Implementation phases

### Phase 0 — Baseline & gate

1. Record a clean baseline: `composer install && composer app-code-quality`
   (fix no pre-existing failures beyond the known `#not covariant#` suppression).
2. Add rector as a dev dependency (`rector/rector`) for mechanical transforms; use
   `PHP_81` set and review escalations individually.
3. Minimum CI matrix: PHP 8.1, 8.2, 8.3 (add `build/Dockerfile.php82`, `.php83` if
   the matrix is docker-based).

### Phase 1 — Fix the broken contract first (highest priority)

1. Fix the implicit-nullable poison pill: `int $workTimeout` → `?int $workTimeout`.
   Ditto any other implicit-nullable property/parameter found by `php -l` on 8.3.
2. Replace the `: void` signatures in `src/Adapter/AbstractAdapter.php` with the real
   return types implemented by all adapters; use unions where they vary:
   - `connect(): bool`, `disconnect(): bool`, `bindRead($queue): bool`,
     `bindWrite($queue): bool`, `afterWorkSuccess($workId): bool`,
     `afterWorkFailed($workId): bool`
   - `pickTask($timeout = null)`: union of the values adapters actually return
     (`bool|array|string|null` — verify per adapter before writing)
   - `putTask($body, $params = [])`: same union-verify exercise
   - `ping($reconnect = true): bool`
   - `hasWorkers($queue): bool` (and fix `Redis::hasWorkers()` to return
     `true`/`false`, not `?int`-vs-`true`)
   - `setWorkTimeout(?int $seconds = null)`: `void` is correct here; keep it
3. Type the remaining parameters while here: `$queue`, `$workId`, `$body`, `$params`.
4. Add a class-load smoke test: `class_exists()` on every concrete adapter/worker/
   publisher to prove no signature-compat fatals remain.
5. Drop the `#not covariant#` ignore from `build/phpstan.neon`.

Do NOT skip ahead: every later phase assumes the adapters load cleanly on 8.1+.

### Phase 1A — Implementation record (deltas vs. this plan, May 2026)

What was actually shipped (verified live on PHP 8.5, `composer install` clean):

- `composer.json`: `require.php` `>=7.4` → `>=8.1`; added `phpunit/phpunit ^10.5`
  (require-dev), `autoload-dev` `BackQ\Tests\ => tests/`, and an `app-tests` script
  (`phpunit --configuration=phpunit.xml`). Platform pin for local runs stays `8.1`.
- Contract (`src/Adapter/AbstractAdapter.php`) typed as planned, with per-adapter
  unions confirmed from the implementations:
  - `pickTask($timeout = null): bool|array` (all adapters)
  - `putTask($body, $params = []): string|int|bool` root union; concrete:
    `Nsq`/`DynamoSQS`: `bool`, `Beanstalk`: `string|bool`, `Redis`: `string|int|bool`
  - `ping($reconnect = true): bool`, `hasWorkers($queue): bool|int|null`
    (parameter REQUIRED in the contract), `setWorkTimeout(?int $seconds = null): void`
- **`$workTimeout` was NOT changed to `?int` as planned** (Phase 1 item 1). Live-check:
  PHP property types are invariant — a child `?int` override of a parent `int`
  property is a fatal error. Instead `AbstractWorker::$workTimeout` was demoted to
  untyped (`public $workTimeout = null;`) and `Worker\Closure`/`Worker\Serialized`
  mirrors left untyped.
- `src/Adapter/Redis/Queue.php`: `$retryAfter`/`$blockFor` demoted to untyped to match
  the vendored `Illuminate\Queue\RedisQueue` parent.
- Logger guards in `AbstractAdapter`/`AbstractWorker` changed to `isset($this->logger)`
  (was `if ($this->logger)` → fatal "must not be accessed before initialization" on a
  typed uninitialized property). Note: the Phase 3 nullsafe suggestion does NOT apply —
  `$this->logger?->…` throws on an *uninitialized* typed property; `isset()` is the
  correct guard.
- `src/Message/Guzzle.php`: `private Request $request` demoted to untyped (constructor
  stores the string form; psr7 v1 `Request` has no `__toString`).
- Tests introduced (so the §7 "no test framework" note is now stale): see
  `tests/` — adapter/worker/publisher/message units + support doubles.
- The legacy GCM/FCM stack (`BackQ\Adapter\Fcm`, `BackQ\Message\Fcm`,
  `BackQ\Worker\Fcm`, `BackQ\Publisher\Fcm`) was removed — it built on the
  unshipped ZF1 `Zend_Mobile_Push_*` classes. Use the FCM HTTP v1 API or AWS
  SNS FCM platform endpoints instead.
- `#not covariant#` in `build/phpstan.neon` is kept for now: full static analysis still
  cannot run in this environment (the `Zend_Http_Client_Adapter_Test` bootstrap class is
  absent). Drop it once a deps-complete CI image can prove a clean run.
- Stray pre-existing issues surfaced by analysis (NOT introduced here; left for later
  phases): `src/Adapter/Redis.php:257` writes `$this->stateData` without a declaration,
  `Backq\Adapter\AbstractAdapter` casing in `src/Publisher/AbstractPublisher.php:13`,
  missing `BackQ\Worker\RuntimeException` in `src/Worker/AProcess.php`.

### Phase 2 — Runtime & dependency floor

1. `composer.json`: `require.php: ">=8.1"`.
2. Widen/raise the dependencies that gained PHP 8.1 support:
   - `symfony/process`: `>=4` → `^5.4 | ^6.4 | ^7.0`
   - `guzzlehttp/psr7`: `^1.9` → `^2.7`
   - `psr/log`: `^1.1` → `^1.1 | ^2.0 | ^3.0`
   - `opis/closure`: `^3.6` → `^3.6 | ^4.0`
   - `illuminate/queue`, `illuminate/redis`: `>=5` → a floor with official 8.1 support
     (target `^10`; adjust for the PHP syntax versions the adapters rely on)
   - keep `aws/aws-sdk-php: ^3`
3. Evaluate pinned/legacy deps on PHP 8.1-8.3:
    - `davidpersson/beanstalk ^2.0`: verify on 8.3; else fix-forward with a local patch
      in `src/Adapter/Beanstalk/Client.php`.
4. Regenerate `composer.lock` on the 8.1 floor and verify `composer install` is clean.
5. Remove `build/Dockerfile.php74`, `build/Dockerfile.php80`.

### Phase 2A — Implementation record (deltas vs. this plan, Sep 2026)

What was actually shipped (verified live on PHP 8.5, `composer install` clean):

- Dependency floors raised exactly as listed in Phase 2 item 2, EXCEPT `opis/closure`:
  kept at `^3.6` (NOT widened to `| ^4.0`). Reason: opis/closure 4.x removed the public
  `SerializableClosure::__construct(Closure)` API (constructor is now private in the 3.x
  shim) that `src/Message/Closure.php` and its tests depend on. Upgrading to 4.x breaks
  `new SerializableClosure($closure)` with a `Call to private ...::__construct()` error.
  If 4.x support is required later, `Message\Closure` must be migrated to
  `Opis\Closure\Serializer`.
- `symfony/process` resolved to 6.4.x-dev, `guzzlehttp/psr7` to 2.13.x-dev,
  `illuminate/*` to 10.x-dev, `aws/aws-sdk-php` to 3.395.7 under the `php: 8.1` platform
  pin. `composer update` now reports "No security vulnerability advisories found"
  (previous run reported 15 advisories; resolved by the aws SDK upgrade).
- psr7 v2 removed the `GuzzleHttp\Psr7\str()` and `parse_request()` function shims.
  `src/Message/Guzzle.php` (and `tests/Message/GuzzleTest.php`) migrated to
  `GuzzleHttp\Psr7\Message::toString()` / `Message::parseRequest()`.
- `build/Dockerfile.php74` and `build/Dockerfile.php80` were already absent from the
  tree. `Dockerfile.php81` is now self-contained: the `# syntax = edrevo/dockerfile-plus`
  custom BuildKit frontend (and the `Dockerfile.php.common` it included) was dropped —
  the frontend image pulled by that directive stalls on Windows builds after loading the
  `composer:2` stage metadata. The shared content was inlined and the composer stage is
  referenced by name (`FROM ... AS composer`, `COPY --from=composer`).
- Full suite green after the floor raise: 73 tests / 177 assertions, exit 0.

### Phase 2B — Implementation record (Phase 3 language modernization, Sep 2026)

- **Constructor property promotion** applied:
  - `src/Adapter/Redis.php` — 9 params promoted (`host`, `port`, `persistent`,
    `persistent_id`, `prefix`, `timeout`, `read_timeout`, `database_id`,
    `auth_password`); `$this->app` binding stays in the constructor body.
  - `src/Adapter/Amazon/DynamoDb/QueueTableRow.php` — `$payload` promoted and typed
    `string`; the constructor was re-parameterized `string $payload` (was `$body` —
    positional callers unaffected); `$id`/`$time_ready`/`$metadata` now typed.
  - `src/Message/Process.php` — all five params promoted (commandline/cwd/env/input/
    timeout).
  - `src/Adapter/IO/StreamIO.php` — `$persistent` promoted to `private string
    $persistent`; the `(bool)` cast of the run-time property was dropped (the promoted
    string is now used directly, matching prior `strval()`/truthiness behaviour).
  - `src/Adapter/Redis/Connector.php` — NOT promoted: it is a passthrough to the
    illuminate parent's constructor, so promotion would create redundant properties.
- **`str_contains`** — `src/Logger.php` (was `strpos`/`strstr`).
- **`$e::class`** — replaced `get_class($e)` / `get_class($e->getPrevious())` in
  `src/Worker/Amazon/SNS/Application/PlatformEndpoint/{Register,Remove,Publish}.php`
  (behaviour identical: `::class` also throws on a null receiver).
- **`final` class constants** — all NSQ protocol/response/frame/state constants in
  `src/Adapter/Nsq.php`.
- **Non-capturing catches** — NOT applied: every `catch (Throwable $ex)` in
  `Redis.php`, `Nsq.php`, `Beanstalk.php`, `Beanstalk/Client.php` references `$ex`
  (→ `getMessage()`/`getCode()`), so the binding must stay.
- **`match` / nullsafe / `never`** — deferred (see 4.2).

### Phase 2C — Implementation record (Phase 4 value objects, enums, attributes, Sep 2026)

- **Attributes** — a first attempt to convert `@SuppressWarnings(PHPMD...)` on
  `src/Adapter/Redis.php:34` to `#[SuppressWarnings('PHPMD.CouplingBetweenObjects')]`
  was REVERTED: installed phpmd/phpmd 2.15 has no PHP-attribute support (its rules engine
  reads the docblock annotation), so the attribute would silently disable the suppression
  on real runs and also trips PHPStan ("Attribute class does not exist"). Keep the
  docblock form until PHPMD supports attributes.
- **enums / readonly** — deferred, with reasons:
  - `readonly class` is PHP 8.2, out of the 8.1 floor (see §7). Properties can be
    `readonly` in 8.1, but `QueueTableRow` is the strongest candidate and it is
    controller-owned; apply when confident no mutation path exists after `fromArray`.
  - `BackQ\Adapter\ConnectionState` enum for the `STATE_*` machine would be internal-only,
    but neither the redis (`ext-redis`) nor NSQ runtime is available in this environment
    to re-verify connect/pick/disconnect flows after the swap; scheduled for a future
    change with integration testing. The integer constants stay for BC meanwhile.

### Phase 2D — Implementation record (Phase 5 static analysis, Sep 2026)

- `build/psalm.xml`: `phpVersion` `7.4` → `8.1` (errorLevel stays 3 for now). Note: the
  vendored Psalm phar embeds a `~8.1.31||~8.2.27||~8.3.16||~8.4.3` runtime constraint, so
  it cannot run on the local PHP 8.5; verify Psalm on CI (php 8.3) or in the docker image.
- `build/phan.php`: `target_php_version` was already `8.1` (ext-ast absent locally;
  `--allow-polyfill-parser` crashes on PHP 8.5 in vendored `symfony/var-exporter`; validate
  in docker/CI).
- `build/phpstan.neon`: level KEPT at `0` (level probe: 1→E2, 2→E30, 3→E39, 4→E66,
  5→E74, 6→E248, 7→E262 — raising is a separate change). `paths` fixed to `../src`
  (it was relative to the config file and previously resolved to `build/src`).
- The `bootstrapFiles` entry for `src/Zend/Http/Client/Adapter/Psr7.php` was REMOVED:
  that class was dead code (extends the never-shipped `Zend_Http_Client_Adapter_Test`,
  referenced by no source, not in composer's autoload) and its removal lets a clean,
  end-to-end analysis run. The now-obsolete `#not covariant#` ignore went with it
  (Phase 1 fixed the covariant returns).
- Pre-existing errors surfaced by the clean run were fixed: `Redis::$stateData`
  property declared; `assert($redis instanceof \Redis)` replaces the non-existent
  `Predis\ClientInterface` (adapter uses the phpredis driver, predis is not installed);
  `Backq\Adapter\AbstractAdapter` → `BackQ\Adapter\AbstractAdapter` casing;
  `Worker\AProcess` gains `use RuntimeException`.
- **Dockerized integration tests** (redis/nsq): `build/Dockerfile.php81` became fully
  self-contained (the `edrevo/dockerfile-plus` frontend and `Dockerfile.php.common` are
  gone — that frontend stalled on Windows after the `composer:2` metadata load) and now
  installs `ext-redis` alongside `ast`. `build/docker-compose.yaml` gained `redis`
  (redis:7-alpine, port 16379, healthcheck via `redis-cli ping`) and `nsq`
  (nsqio/nsq:v1.3.0 `/nsqd`, ports 14150/14151, healthcheck via `/ping`) services;
  `app.php81` `depends_on` both (service_healthy). New `tests/Adapter/RedisAdapterTest.php`
  and `tests/Adapter/NsqAdapterTest.php` round-trip publish→pick→finalize against the
  real services (hosts/ports via `BACKQ_REDIS_HOST`/`BACKQ_REDIS_PORT` and
  `BACKQ_NSQD_HOST`/`BACKQ_NSQD_PORT` set by the compose app service) and
  `markTestSkipped` when the service or ext is absent. `composer app-tests` now boots the
  redis+nsq+app stack (`up -d --wait`) and runs phpunit inside the app container via
  `docker compose exec`; the old host-side invocation survives as `composer app-tests-local`.

### Phase 3 — Remaining language modernization (deferred items follow)

Promotion, `str_contains`, `$e::class` and `final` constants shipped in 2B. Still open,
hand-review against rector's `PHP_80`/`PHP_81` sets if adopted:

- **Nullsafe operator `?->`** — the `if ($this->logger)` guards were NOT converted.
  Phase 1A record explains why: `isset($this->logger)` is required because `?->` throws
  on an uninitialized typed property (see `src/Adapter/AbstractAdapter.php:109-138`,
  `src/Worker/AbstractWorker.php:145-186`).
- **Non-capturing catches** — none applicable: every `catch (Throwable $ex)` references
  `$ex` (→ `getMessage()`/`getCode()`), so the binding must stay.
- **`match`** — convert `if/elseif` value-dispatch chains in `Nsq` frame handling
  where it reads cleaner than the current form.
- **`never` return type** — only for functions whose end is unreachable (all paths throw
  or `exit`); audit before using, do not force.
- **`array_is_list`/first-class callables** — opportunistic only.

### Phase 4 — Value objects, enums, attributes

Status: **deployed as Phase 2C record** (see above); the attribute swap was reverted
and enums/readonly deferred, so this phase is largely open work.

1. **`readonly` classes** — start with `QueueTableRow` (populated once in constructor,
   never mutated; confirm against `DynamoSQS` usage). Convert message/DTO classes such
   as `src/Message/*` and `src/Adapter/Amazon/DynamoDb/QueueTableRow.php` only where no
   mutation path exists. Migration to `__serialize`/`__unserialize` pairs is required
   before applying `readonly` to any serialized payload class.
2. **Enums** — introduce a shared `BackQ\Adapter\ConnectionState` backed enum for the
   `STATE_*` machine in `Redis` and `Nsq`. Internal property, so no wire-format impact;
   keep the integer public constants alongside for downstream BC, or remove them in the
   major (record in `UPGRADING`). Do **not** put enum cases into queue payloads —
   serialized messages traverse `src/Message/Serialized.php` between queue hops and old
   rows must keep decoding.
3. **Attributes** — the `@SuppressWarnings(PHPMD.CouplingBetweenObjects)` docblock on
   `src/Adapter/Redis.php:34` will stay in docblock form: installed phpmd 2.15 does not
   read PHP attributes, and PHPStan errors on the synthetic class
   ("Attribute class does not exist"). Revisit only after PHPMD ships attribute support.
   Keep `@var` only where PHPStan/Psalm still need it; prefer native mixed/union types.

### Phase 5 — Static analysis hardening

Status: **partially deployed as Phase 2D record** (see above); psalm/phan bumped,
phpstan level raise deferred until the Zend bootstrap entry can go.

1. `build/psalm.xml` — `phpVersion="8.1"` (done); then tighten `errorLevel` 3 → 2 once
   `MissingReturnType`/`MissingParamType`/`MissingPropertyType` noise is gone (rector or
   `psalm --alter --issues=MissingParamType,MissingReturnType` can auto-add them).
2. `build/phpstan.neon` — raise `level: 0` stepwise to ≥ 5 in a separate change (clean
   run at level 0 achieved, record 2D; probe showed 2 errors at level 1, needing the
   mixed/property-typing work before bumping).
3. `build/phan.php` — bump the `target_php_version` to `8.1` (already done, record 2D).
4. Keep the `XDEBUG_MODE=off` composer scripts as-is.

### Phase 6 — Docs & release notes

Status: **docs done (UPGRADING/AGENTS/plan); minor `char` verification only.**

1. `UPGRADING` — new 4.0 section:
   - Backward incompatible: PHP >= 8.1 requirement; adapter contract return types
     (consumers overriding adapter methods must declare compatible return types);
     any enum/readonly conversions; removed 7.4/8.0 docker targets; psr7 >= 2.7
     (`Message::toString`/`parseRequest`); dependency floors
     (symfony/process, illuminate/* ^10, psr/log, opis stays ^3.6).
   - New features: promotion/readonly/enum refactors, raised dependency floors.
2. `README.md` — drop the 7.4 references, note 8.1 requirement (verified: no 7.4
   references remain; the `^3.0` occurrence is the package tag line, unrelated).
3. `AGENTS.md` — update the "Environment" section once the floor lands (done).

## 5. Risk register

| Risk | Mitigation |
|---|---|
| Adding return types to `AbstractAdapter` breaks third-party adapters that override the methods | accept only in the major; document loudly in `UPGRADING` |
| `davidpersson/beanstalk` fails on 8.1-8.3 | verify in Phase 2 early; budget for replacement/patches |
| Enums/readonly change `serialize()` output of message objects | keep payload classes non-readonly until `__serialize/__unserialize` migration; no enum cases in wire data |
| Rector bulk-edit introduces behavioral drift | land per-phase PRs; diff review by `git diff`; keep `app-code-quality` and smoke tests green each PR |
| Psalm error level too aggressive on a library with legacy vendor contracts | walk level 3→2 via PRs, not a single all-in change |
| PHPStan level > 0 surfaces 2+ structural errors (mixed types on legacy `Adapter\*` and `Worker\*` signatures) | defer; track in the phpstan-raise follow-up, level stays 0 until then |

## 6. Acceptance criteria

1. `composer install` clean with `php >= 8.1` constraint resolved on PHP 8.1/8.2/8.3.
2. `php -l` clean over `src/` and `example/` on PHP 8.3 (no implicit-nullable fatals).
3. Every concrete adapter/worker/publisher class loads without signature-compatibility
   fatal (smoke test from Phase 1 is part of the suite).
4. `composer app-code-quality` green (phpcs, phpcbf, phpstan ≥ level 5, psalm level ≤ 2,
   phpmd, phan, pdepend).
5. No `#not covariant#` suppression remains.
6. `UPGRADING` documents all breaks; README/AGENTS reflect `8.1`.
7. Publish → work round-trip still passes for the Redis example flow in the README.

## 7. Out of scope

- PHP > 8.1 features (8.2/8.3): only guarded by CI matrix; no deliberate adoption.
- Async/fiber rewrite of the worker loop.
- Test-suite introduction (no test framework exists today; smoke tests only).
- Replacing the vendored `src/Zend/` compatibility shim.