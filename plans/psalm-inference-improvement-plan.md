# Psalm Type-Inference Improvement Plan (88.31% → ≥ 95%)

> **EXTENDED** — `plans/psalm-and-phan-inference-plan.md` (2026-09-23) re-scopes
> this same goal together with the 147-error fix list and adds a "maximise Phan
> type-inference" goal (§5) with proxy metrics. This file remains for the Psalm
> inference-% measurement detail.

> **DONE (Psalm-error portion, 2026-09-23)** — the 147-error fix executed on
> branch `psalm-error-fixes`. Re-measured `--stats` inference:
> **92.3495%** (baseline 88.3122%), with 0 errors at `errorLevel=3` (no
> suppressions). The ≥ 95% Phase 3–4 roadmap below remains future work.

- Status: **proposed** — plan only, no code changes yet.
- Goal: raise `Psalm was able to infer types for X% of the codebase` from the
  current **88.3122%** to **≥ 95%** on the main track, **≥ 98%** as stretch, and
  keep it there with a CI gate.
- Related: `plans/psalm-error-fix-plan.md` (fix the 145 soundness errors first —
  the same lines), `plans/php-8.3-and-repository-review-improvement-plan.md` §3.3
  (contract typing is a 5.0 BC change this plan piggybacks on), `build/psalm.xml`,
  `composer.json` (`app-psalm`).
- Constraint: `build/psalm.xml` sets `errorLevel="3"` and **`disableSuppressAll="true"`**
  → `@psalm-suppress` is inactive, so every finding is fixed by typing/code, never by
  suppression.

---

## 1. Baseline & how to measure

Reproduced on `backq.php83` (Sep 2026):

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --no-diff --stats --disable-extension=xdebug"
```

`--stats` prints the per-file table below; `composer app-psalm` runs the same
(plus `--show-info=true`). The metric is a per-file weighted count of **mixed**
expressions. The whole codebase has **304 mixed** today, which maps to
≈ 2,600 inferred-type slots (derived: 304 / (1 − 0.883122)). So:

| Overall % | remaining `mixed` budget |
|---|---|
| 92% | ≤ 208 (kill ≈ 96) |
| 95% | ≤ 130 (kill ≈ 174, ~57%) |
| 97% | ≤ 78 (kill ≈ 226, ~74%) |
| 98% | ≤ 52 (kill ≈ 252, ~83%) |

### Current per-file state (from `--stats`, 2026-09-23)

| File | mixed | % | Target mixed | Main inputs to the score |
|---|---|---|---|---|
| `Adapter/Nsq.php` | 83 | 81.718 | ≤ 10 | `$config`/`$stateData` arrays, contract params, `unpack()`, `json_decode` |
| `Adapter/IO/StreamIO.php` | 37 | 84.188 | ≤ 2 | `$sock`, ctor params, `fread`/`fwrite`/`stream_get_meta_data` |
| `Adapter/Redis.php` | 34 | 91.542 | ≤ 4 | `reservedJobs` docblock, `payload()['data']`, illuminate dynamic APIs |
| `Worker/AProcess.php` | 29 | 79.433 | ≤ 2 | generator `$payload`, `$message`, `$forks`, `$run` flag trick |
| `Adapter/Beanstalk.php` | 19 | 89.785 | ≤ 2 | contract params, `Client::stats/reserve` returns |
| `Adapter/DynamoSQS.php` | 19 | 89.730 | ≤ 2 | SQS `Result::get()`, `crc32`/`getmypid`, `putItem` response |
| `Worker/AbstractWorker.php` | 15 | 90.506 | ≤ 2 | `$queueName`/`$adapter`/`$bind`, `$job[0]`/`$job[1]` |
| `Publisher/AbstractPublisher.php` | 11 | 81.667 | ≤ 1 | `$bind`/`$queueName`/`$adapter`, `__sleep` vars |
| `Worker/…/PlatformEndpoint/Register.php` | 11 | 84.507 | ≤ 2 | `$endpointResult`, `$reprocessedTasks`, `$snsClient` cascade |
| `Worker/Guzzle.php` | 8 | 87.692 | ≤ 1 | `$payload`, promise callback params, `json_encode` |
| `Worker/…/PlatformEndpoint/Publish.php` | 7 | 91.667 | ≤ 1 | `@unserialize($payload)`, `onFailure` types |
| `Worker/…/PlatformEndpoint/Remove.php` | 7 | 89.552 | ≤ 1 | `@unserialize($payload)`, `$delSuccess` |
| `Adapter/Redis/Connector.php` | 4 | 76.471 | ≤ 1 | `$config['queue']`, `retry_after` null |
| `Logger.php` | 4 | 78.947 | 0 | `$logFile`, `getmypid()` concat, `fopen` |
| `Worker/Closure.php` | 3 | 93.023 | ≤ 1 | `@unserialize($payload)` |
| `Worker/Serialized.php` | 3 | 95.161 | ≤ 1 | `@unserialize($payload)` |
| `Adapter/Amazon/DynamoDb/QueueTableRow.php` | 2 | 95.238 | ≤ 1 | `metadata` reads |
| `Message/Guzzle.php` | 2 | 92.000 | 0 | `$request`/`$scheme` props, `getRequest()` |
| `Adapter/Beanstalk/Client.php` | 2 | 98.734 | ≤ 2 | vendored-adjacent; keep |
| `Worker/…/PlatformEndpoint.php` | 2 | 90.909 | 0 | `strrpos` false branch |
| `Message/Generic.php` | 2 | 81.818 | 2 (keep) | intentional `mixed $data` payload |

Sum of targets ≈ **38 mixed remaining** → ≈ **98.5%** stretch. The 95% goal is met
once roughly the top-8 files are done.

---

## 2. Why the score is low — the multipliers

Most of the 304 mixed comes from a few *multiplicative* root causes, not from
isolated sloppy code:

### M1 — The `AbstractAdapter` contract is untyped and flows everywhere
`src/Adapter/AbstractAdapter.php` declares untyped params and a loose generator
channel:

- `bindRead($queue)`, `bindWrite($queue)`, `pickTask($timeout = null)`,
  `putTask($body, $params = [])`, `afterWorkSuccess($workId)`,
  `afterWorkFailed($workId)`, `ping($reconnect = true)`, `hasWorkers($queue)` —
  every adapter (`Nsq`, `Beanstalk`, `DynamoSQS`, `Redis`) re-inherits the mixed
  params, and each `$params[...]`/`$workId`/`$queue` read inside them is mixed.
- `AbstractWorker::work()` yields `mixed|null` payloads
  (`@psalm-return \Generator<int|mixed, mixed|null, mixed, null>`): **every**
  worker's `$payload` in the `foreach ($work as ...)` loop is `mixed`, which
  then poisons `@unserialize($payload)` (mixed|false), the `gettype()` calls and
  the `if (!$payload)` guards. This single channel is the cause of most of the
  AProcess (29), Register (11), Publish (7), Remove (7), Guzzle (8), Closure (3),
  Serialized (3) mixed.

Modelling the contract as
`pickTask(): bool|array{0: int|string, 1: string, 2?: array<string,mixed>}` and
`work(): \Generator<int|string, string|null, bool|null, null>` removes ≈ 90+
mixed across 10 files at once.

### M2 — Malformed docblocks that cascade
- `Redis.php:61-64` — `/** @var []\Illuminate\Queue\Jobs\RedisJob */` — invalid
  type token; `$reservedJobs` is effectively fully-typed-mixed, so every
  `$this->reservedJobs[$id]` read/write (afterWorkSuccess/Failed, pickTask,
  disconnect) is mixed *and* produces the `PossiblyNullArrayOffset` errors.
- `Worker/Amazon/SNS/Application.php:19,24` — `/** @var $snsClient AwsSnsClient */`
  (misplaced variable) + `@param AwsSnsClient` (class does not exist) → `$snsClient`
  resolves to `mixed`, so `$this->snsClient->…` in all three SNS workers is mixed.
- `StreamIO.php` — `@param null $read_write_timeout` / `@param null $context`
  (lines 80-82) force `null` onto the ctor params (see `NoValue` errors) and make
  every read of those params mixed or dead.

Fixing M2 removes cascades in Redis (≈ 10), the SNS workers (≈ 20) and StreamIO.

### M3 — Native functions psalm cannot type without help
`unpack()` (false|array), `json_decode`/`@json_decode` (mixed), `crc32()` int|false,
`getmypid()` int|false, `gethostname()` string|false, `stream_get_meta_data()`
(array with dynamic keys), `fread`/`fwrite` (int|false), `fopen` (resource|false),
`stream_get_line`/`stream_get_contents` (string|false). These show up heavily in
`Nsq` (unpack, json_decode, getmypid/gethostname), `DynamoSQS` (crc32/getmypid,
SQS `Result::get()`), `StreamIO`, `Logger`. Each needs a local `@psalm-var`/cast.
A guarded `unpack()` helper (`throw` on `false`, return typed int) kills 8 Nsq
findings at once.

Other sources: illuminate/AWS/Symfony dynamic APIs (`RedisManager::isConnected`
magic methods, AWS SDK `Result::get()`, `RedisJob::payload()` shapes) — the SDK
stubs are intentionally open, so narrow them at the call site with
`/** @var … */` + asserts, never suppressions.

---

## 3. Roadmap

Work is per-branch/PR, verified in the container per `AGENTS.md`. **Do the
`psalm-error-fix-plan` first (or per-file interleaved):** every error fixed is the
same line that scores as mixed, so errors and percentage move together. Each phase
ends by re-running the `--stats` command and updating the table in §1.

### Phase 0 — Measure & gate
1. Add a small script `app-psalm-stats` (the §1 command) so the metric is one
   command, and record the current table in this file.
2. Baseline checkpoints: before/after every phase.

### Phase 1 — Docblock-only typing, no runtime/BC change (no `@psalm-*` on hot paths that alter behavior)
Fix M2 first — highest ratio per line changed:
1. `Redis.php:61-64` → `/** @var array<string, RedisJob> $reservedJobs */`;
   `$retryAfter` → `@var ?int`; `$connected` → `@var bool` (and Nsq/Beanstalk/
   DynamoSQS equivalents).
2. `Worker/Amazon/SNS/Application.php` → type `$snsClient` as `SnsClient`
   (`@var SnsClient $snsClient` + `@param SnsClient`) — collapses the SNS worker
   cascade.
3. `StreamIO.php` ctor docblocks → `@param int $connection_timeout`,
   `@param int|null $read_write_timeout`, `@param resource|array|null $context`;
   `@var resource|null $sock`.
4. Give `Nsq::$config` a literal shape:
   `@var array{host: string, port: int, clientId: string, auth: string, connection_timeout: int, stream_set_timeout: int, persistent: bool, heartbeat_interval_ms: int, msg_timeout: int, max_req_timeout: ?int}`.
5. `AbstractAdapter` params via docblock `@psalm-param`/`@psalm-return` (deferred
   to native in Phase 2; docblock keeps it non-BC):
   `@psalm-param string $queue`, `string|int $workId`, `mixed $body`,
   `array<string,mixed> $params`, and
   `@psalm-return bool|array{0: int|string, 1: string, 2?: array<string,mixed>}`
   for `pickTask()`. Align children (`Nsq`, `Beanstalk`, `DynamoSQS`, `Redis`)
   with matching docblocks — this is the M1 lever.
6. `AbstractWorker::work()` → `@psalm-return \Generator<int|string, string|null, bool|null, null>`
   (payload channel), `$queueName`/`$bind`/`$adapter`/`$delaySignalPending` →
   `@var string`/`bool`/`AbstractAdapter`/`int`; same for `AbstractPublisher`.

**Expected: 88.31% → ≈ 92–93%** (M1+M2 dominate; no runtime risk).

### Phase 2 — Generator & contract native typing (5.0 BC — piggyback on the 8.3 plan §3.3)
1. Native-type `AbstractAdapter` methods exactly as the Phase 1 docblocks.
2. In `AbstractWorker::work()`, after `is_array($job)`, narrow with
   `/** @var array{0: int|string, 1: string, 2?: array} $job */`; yield
   `$job[0] => $job[1]` as `int|string => string`, and `yield null` for the
   empty loops.
3. In each worker, replace `@unserialize($payload)` + flag-var narrowing with an
   explicit `is_string($payload)` + early `continue`/instanceof restructure
   (AProcess, all SNS workers, Guzzle, Closure, Serialized).

**Expected: ≈ 95–96%** (workers now typed; most `PossiblyNullReference` and
`PossiblyNullArgument` errors from the error plan disappear too).

### Phase 3 — Property & method native typing (5.0) + hotspot refactors
Per file, the moves from the §1 table (details in the error-fix plan):

- `Nsq.php` — type `$stateData` (`array{queue: string, rdy?: int}`),
  `$authentication: bool`, `$connected: bool`; add a guarded `unpack` helper
  (`readUint16`/`readUint32`/`readTimestamp`, `throw` on `false` → typed int);
  `json_validate` + `@psalm-var array{auth_required: bool, ...}` on identify
  features; fix `use Datetime` → `use DateTime` and `(string) $time`.
- `StreamIO.php` — native: `private $sock` → `resource|null` with
  `@psalm-assert !null` style guards; ctor param types; `read()`/`stream_get_line`/
  `stream_get_contents` return `string|false`; log `$t->getCode()` as `(int)`.
- `AProcess.php` — `$forks = []` → `array<int, Process>`; `$queueName: string`;
  restructure `$run` bool-then-Closure flag into a typed closure or direct call;
  drop dead `$ec = null` store and the never-false `true !== $processed` check.
- `Beanstalk.php`/`DynamoSQS.php`/`Redis.php` — native param types inherited from
  the contract; `$maxNumberOfMessages: int`; `$msgid = (string) crc32(...)`;
  shape-assert SQS `Messages`/`@metadata`/Redis `payload()`.
- `Logger.php` — `protected string $logFile`; guard `fopen` result.
- `Message/Guzzle.php` — `$request: string`, `$scheme: ?string`.

**Expected: ≈ 97%+**, the §1 budget reached.

### Phase 4 — Escalate the level & gate it
1. After the error plan is green and Phase 3 done, move psalm `errorLevel="3"` → `2`
   (records as info any residuals) and re-check; only go further if the errors plan
   is stable.
2. Add a CI job step that greps the `--stats` output for `was able to infer types
   for` and fails below the agreed threshold (start 92, raise to 95 after Phase 2).
3. Track the §1 table at each phase; a PR that lowers the score by more than 0.5pt
   must be amended before merge.

---

## 4. Verification (docker, per `AGENTS.md`)

Per touched file, in order: `php -l`, `phpcs --no-cache`, phpstan on the changed
file **alone** (reproduces the pre-commit hook's file set — watch child-parameter
rules when typing the contract; widen the parent, not the child). Then psalm stats:

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --no-diff --stats --disable-extension=xdebug"
```

Before merge: full `composer app-code-quality` + `php ./vendor/bin/phpunit
--configuration=phpunit.xml` green in the container; `phpcbf` idempotent
("No violations were found").

---

## 5. Risk register

| Risk | Mitigation |
|---|---|
| Native typing of `AbstractAdapter` params breaks external subclasses (BC) | Phase 1 is docblock-only (non-BC); native typing ships only in 5.0 alongside the 8.3 plan §3.3, with UPGRADING entries |
| PHPStan child-parameter findings when typing the parent contract | Type the parent to the widest child signature (see AGENTS.md quirk); run phpstan on the changed child alone first |
| Illuminate/AWS/Symfony dynamic APIs keep emitting | Narrow at call site with `/** @var */`+asserts; never `@psalm-suppress` (disabled); if a stub truly lacks a method, document a local interface, don't loosen |
| `mixed` is sometimes the correct type (`AbstractAdapter::$body`, `Message\Generic::$data`) | Keep it; count those 2–4 mixed in the budget instead of chasing them |
| `--alter` (from the error plan) rewrites lots of code at once | Run on a branch, review the diff, full suite before merging |
| Percentage regresses on unrelated PRs | CI stats gate (Phase 4) |

## 6. Out of scope

- Fixing the 145 error-plan findings beyond what reduces mixed (that plan owns them).
- Vendor stubs for illuminate/AWS (we narrow at call sites instead).
- Psalm taint analysis findings (`app-psalm-taint`).
- Moving `Message\Generic` off `mixed` (intentional).