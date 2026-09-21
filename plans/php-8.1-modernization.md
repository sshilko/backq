# PHP 8.1 Modernization Plan

- Status: proposed
- Target: `php >= 8.1` (currently `>= 7.4`, see `composer.json`)
- Owns: this plan lives in `plans/` and is implemented in follow-up change(s)
- Related: `AGENTS.md`, `build/psalm.xml`, `build/phpstan.neon`, `build/Dockerfile.php{74,80,81}`

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
| 2 | Abstract methods declared `: void` while every concrete adapter omits the return type and returns `bool`/`array`/`string`/`false`. This fails PHP's signature-compatibility check when a concrete adapter class is loaded (verified fatal). PHPStan currently papers over it with `#not covariant#` | `src/Adapter/AbstractAdapter.php:34-88`, all adapters (`Redis`, `Beanstalk`, `Nsq`, `DynamoSQS`, `ApnsdPush`, `Fcm`) |
| 3 | `hasWorkers(): ?int` always `return true;` — type error when invoked | `src/Adapter/Redis.php:399` |
| 4 | Constructor assigns parameters to identical typed properties | `src/Adapter/Redis.php:121-168`, `src/Adapter/Amazon/DynamoDb/QueueTableRow.php:31`, `src/Message/Process.php:16-24`, `src/Adapter/IO/StreamIO.php:67-85` |
| 5 | `if ($this->logger) { $this->logger->info(...) }` guards | `src/Adapter/AbstractAdapter.php:109-138`, `src/Worker/AbstractWorker.php:145-186` |
| 6 | Value-object that is fully populated in constructor and never mutated afterwards — `readonly` candidate | `src/Adapter/Amazon/DynamoDb/QueueTableRow.php:18-76` |
| 7 | Internal integer "state machine" constants | `src/Adapter/Redis.php:38-46`, `src/Adapter/Nsq.php:73-75` |
| 8 | `false !== strpos(...)` | `src/Logger.php:35` |
| 9 | `get_class($e)` in log/diagnostics | `src/Worker/Amazon/SNS/Application/PlatformEndpoint/{Register,Remove,Publish}.php` |
| 10 | Docblock annotations used for tooling metadata | `src/Adapter/Redis.php:34` (`@SuppressWarnings(PHPMD...)`), `@var` annotations across `src/` |
| 11 | Tooling still targets 7.4 / level 0 | `build/psalm.xml:13`, `build/phpstan.neon:9` |
| 12 | PHP 7.4/8.0 docker images still shipped | `build/Dockerfile.php74`, `build/Dockerfile.php80` |
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
   - `duccio/apns-php =1.0.1` (released 2015): try it, and if unusable replace the
     APNS legacy code path with the self-contained `ApnsdPush` adapter or a maintained
     substitute, removing the dependency.
   - `davidpersson/beanstalk ^2.0`: verify on 8.3; else fix-forward with a local patch
     in `src/Adapter/Beanstalk/Client.php`.
4. Regenerate `composer.lock` on the 8.1 floor and verify `composer install` is clean.
5. Remove `build/Dockerfile.php74`, `build/Dockerfile.php80`.

### Phase 3 — Language modernization (mechanical first)

Apply rector's `PHP_80` and `PHP_81` sets, then hand-review. Expected, per area:

- **Constructor property promotion** — `src/Adapter/Redis.php (121-168)`,
  `src/Adapter/Amazon/DynamoDb/QueueTableRow.php`, `src/Message/Process.php`,
  `src/Adapter/IO/StreamIO.php`, `src/Adapter/Redis/Connector.php`.
- **Nullsafe operator `?->`** — replace the `if ($this->logger)` guards in
  `src/Adapter/AbstractAdapter.php:109-138` and `src/Worker/AbstractWorker.php:145-186`.
- **`str_contains`** — `src/Logger.php:35`.
- **`$e::class`** — replace `get_class($e)` diagnostics in
  `src/Worker/Amazon/SNS/Application/PlatformEndpoint/*.php`.
- **Non-capturing catches** — `catch (Throwable $ex)` where only the message is used
  (`src/Adapter/Redis.php:251`, `src/Adapter/Nsq.php:185,454`, `src/Adapter/Beanstalk.php`,
  `src/Adapter/Beanstalk/Client.php`); keep the binding where the variable is referenced.
- **`match`** — convert `if/elseif` value-dispatch chains in `Nsq` frame handling and
  the Fcm/Apnsd worker result handling where it reads cleaner than the current form.
- **`never` return type** — only for functions whose end is unreachable (all paths throw
  or `exit`); audit before using, do not force.
- **`final` class constants** — mark leaf constants `final` where safe (e.g. protocol
  strings in `src/Adapter/Nsq.php:40-75`).
- **`array_is_list`/first-class callables** — opportunistic only.

### Phase 4 — Value objects, enums, attributes

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
3. **Attributes** — replace tooling docblocks with PHP 8 attributes where the tools
   support them:
   - `@SuppressWarnings(PHPMD.CouplingBetweenObjects)` on `src/Adapter/Redis.php:34`
     → `#[SuppressWarnings('PHPMD.CouplingBetweenObjects')]` (PHPMD supports attributes
     from 2.12).
   - keep `@var` only where PHPStan/Psalm still need it; prefer native mixed/union types.

### Phase 5 — Static analysis hardening

1. `build/psalm.xml` — `phpVersion="8.1"`; then tighten `errorLevel` 3 → 2 once
   `MissingReturnType`/`MissingParamType`/`MissingPropertyType` noise is gone (rector or
   `psalm --alter --issues=MissingParamType,MissingReturnType` can auto-add them).
2. `build/phpstan.neon` — raise `level: 0` stepwise to ≥ 5 after Phase 1 removes the
   covariant-return suppression. Remove the now-obsolete `bootstrapFiles` entry for
   `src/Zend/Http/Client/Adapter/Psr7.php` if it is no longer needed.
3. `build/phan.php` — bump the `target_php_version` to `8.1`.
4. Keep the `XDEBUG_MODE=off` composer scripts as-is.

### Phase 6 — Docs & release notes

1. `UPGRADING` — new 4.0 section:
   - Backward incompatible: PHP >= 8.1 requirement; adapter contract return types
     (consumers overriding adapter methods must declare compatible return types);
     any enum/readonly conversions; removed 7.4/8.0 docker targets.
   - New features: promotion/readonly/enum refactors, raised dependency floors.
2. `README.md` — drop the 7.4 references, note 8.1 requirement.
3. `AGENTS.md` — update the "Environment" section once the floor lands.

## 5. Risk register

| Risk | Mitigation |
|---|---|
| Adding return types to `AbstractAdapter` breaks third-party adapters that override the methods | accept only in the major; document loudly in `UPGRADING` |
| `duccio/apns-php` / `davidpersson/beanstalk` fail on 8.1-8.3 | verify in Phase 2 early; budget for replacement/patches |
| Enums/readonly change `serialize()` output of message objects | keep payload classes non-readonly until `__serialize/__unserialize` migration; no enum cases in wire data |
| Rector bulk-edit introduces behavioral drift | land per-phase PRs; diff review by `git diff`; keep `app-code-quality` and smoke tests green each PR |
| Psalm error level too aggressive on a library with legacy vendor contracts | walk level 3→2 via PRs, not a single all-in change |

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