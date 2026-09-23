# Psalm Error Fix Plan (145 errors → 0)

- Status: **proposed** — plan only, no code changes yet.
- Scope: fix the **145 Psalm errors** reported at `errorLevel=3` one by one
  (baseline: Sep 2026, `backq.php83` container). Info-level issues (190) are not
  in scope.
- Related: `plans/psalm-inference-improvement-plan.md` (fixing these errors also
  raises the 88.31% inference score — same lines).
- Remember: `build/psalm.xml` has `disableSuppressAll="true"` — `@psalm-suppress`
  is ignored. All fixes are typing/code changes, no suppressions.

## Reproduce

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --no-diff --disable-extension=xdebug"
```

Per-file fast loop (same flags, one file):

```
php ./vendor/bin/psalm.phar <flags> --config build/psalm.xml src/Adapter/Nsq.php
```

## Order of attack

1. **Auto-fix batch (36 issues).** On a branch run:
   `vendor/bin/psalm.phar ... --alter --issues=UnusedVariable,MissingOverrideAttribute,ClassMustBeFinal`
   (dry-run first), review the diff, keep only sane changes.
2. **Malformed docblocks (fix first — they cascade):**
   - `src/Adapter/Redis.php:61-64` — `@var []\Illuminate\Queue\Jobs\RedisJob` → `@var array<string, RedisJob>`
   - `src/Worker/Amazon/SNS/Application.php:19,24` — `@var $snsClient`/`AwsSnsClient` → real `SnsClient` type
3. **Redundant-checks cleanups** — `RedundantCast`, `RedundantCondition`,
   `RedundantPropertyInitializationCheck`, `UnusedVariable`, `UnusedFunctionCall`,
   `UnevaluatedCode`, `NoValue`. Numerous cheap wins.
4. **Narrow the worker payload channel** — type `AbstractWorker::work()` yields as
   `string|null`; guard `$payload` with `is_string` before `@unserialize`. This
   clears the bulk of `PossiblyNullArgument`/`PossiblyNullReference` in all workers.
5. **Per-file backlog** — the checklist below, largest files first.
6. **Verify zero errors**, then full quality suite + PHPUnit.

## Checklist (145 errors by file)

Ordered by count.

### `src/Adapter/Beanstalk/Client.php` — 24
- L48 `ImplementedReturnTypeMismatch` — `__destruct()` `@return null|true` vs parent `null` → align return type
- L54/79/85/86/91 `PossiblyUndefinedStringArrayOffset` — `_config['persistent'|'timeout'|'host'|'port']` → give `_config` an array shape or `??` defaults
- L88 `InvalidArgument` — StreamIO ctor arg 4 typed `null` by its docblock (see StreamIO entry) → fix StreamIO, not the call
- L107 `MoreSpecificImplementedParamType` — `reserve($timeout = null)` narrowed to `null` → widen to `int|null`
- L114/122/235/257/278 `PossiblyNullReference` — `$this->_io` possibly null → guard/assert before use
- L115 `NoValue` — `$timeout` dead after L107 fix disappears
- L133/293 `InvalidScalarArgument` — `strtok($readio, ' ')` → assert `is_string`
- L168/185 `UnevaluatedCode` — dead `break;` after `return`/`throw` → remove
- L188 `PossiblyFalseOperand` — `$status` concat → assert string
- L216 `ImplementedReturnTypeMismatch` — `statstube()` return widened beyond parent → align
- L224 `InvalidArgument` — `_statsRead` typed bool vs string passed → widen `_statsRead`
- L299 `PossiblyInvalidArgument` — `_decode($data)` → assert `is_string`
- L301 `InvalidOperand` + `PossiblyFalseOperand` — `$decode`/`$status` concat → assert types

### `src/Adapter/Redis.php` — 21
- L64 `InvalidDocblock` — see order-of-attack #2
- L119/124/129/134 `MissingOverrideAttribute` — add `#[Override]` to anonymous-class methods (auto-fix batch covers)
- L170 `RedundantCast` — drop `(int)`
- L172/175 `InvalidOperand` — `$seconds` nullable in log strings → interpolate `?int` via `(int)` / `?? ''`
- L197/251 `RedundantCondition` — `$this->queue` never falsy → drop `&& $this->queue`, use `assert` where needed
- L198 `UndefinedInterfaceMethod` — `getRedis()` not on `Queue` interface → `/** @var Queue $redisQueue */` already present; type the local var as the concrete `RedisQueue`
- L200/219 `UndefinedMagicMethod` — illuminate `RedisManager::isConnected/disconnect` are magic → add `@method` stub or call through a documented local interface
- L413/421 `RedundantCondition`/`TypeDoesNotContainType` — `BLOCKFOR_EMULATE` (const 0) branches are dead → drop const-if or make it a settable bool
- L418 `UnusedVariable` — redundant `$redisJob = null;` → remove init
- L450/456 `PossiblyNullArrayOffset` — `$redisJob->getJobId()` returns `null|string` → guard non-null before offset
- L471 `PossiblyUndefinedStringArrayOffset` — `payload()['data']` → shape-assert the payload
- L575 `InvalidArgument` — `RedisManager::__construct` expects `Application`, gets `Container` → align types

### `src/Adapter/Nsq.php` — 18
- L122 `PossiblyFalseOperand`/`InvalidOperand` — `gethostname()`/`getmypid()` false-able → `(string)` casts
- L136 `RedundantCast` — drop `(int)`
- L156/591/645 `PossiblyNullReference` — `$this->_io` → assert `instanceof StreamIO` after connect
- L322/325/634 `PossiblyUndefinedIntArrayOffset`/`PossiblyInvalidArrayAccess` — `unpack()[1]` → guarded helper (throw on `false`, typed int), 8 findings die at once
- L326 `InvalidClass` + `InvalidScalarArgument` — `use Datetime` → `DateTime`; `$time` float → cast for `createFromFormat`
- L370 `InvalidOperand` — float concat → `(int)` in message
- L467 `PossiblyFalseArgument` — `json_encode($identify)` → `JSON_THROW_ON_ERROR`
- L488 `PossiblyUndefinedStringArrayOffset` — `$features['auth_required']` → shape of identify response

### `src/Worker/AProcess.php` — 16
- L94/103/121/122/126/135/136/137/150/151/152/153 `PossiblyNullReference`/`PossiblyInvalidArgument` — `$message` not recognized as narrowed (flag-var trick) → restructure to early `if ($message instanceof Message\Process)` + `continue`; assert `$cmd` is `string|array`
- L184 `RedundantCondition` — assert redundant once `$forks` typed `array<int, Process>` → drop
- L209 `UnusedVariable` — dead `$ec = null;` store → restructure exit-code handling
- L236 `TypeDoesNotContainType` — `true !== $processed` never true → drop or assert

### `src/Adapter/DynamoSQS.php` — 13
- L179 `RedundantCast` — drop `(int)`
- L185/186/191/206 `PossiblyNullArgument`/`PossiblyNullArrayAccess` — `$result->get('Messages')` mixed|null → `/** @var list<array{Body: string, ReceiptHandle: string}> */` shape-assert before indexing
- L188 `UnusedVariable` — inline `$messageBody`
- L236 `PossiblyFalseOperand`/`InvalidOperand` — `getmypid`/`gethostname` → `(string)` casts
- L241 `InvalidScalarArgument` — `$msgid` int into `QueueTableRow` (string) → `(string) crc32(...)`
- L247 `PossiblyNullArrayAccess` — `$response['@metadata']` → shape-assert
- L267 `RedundantCondition` — redundant `assert($sqs instanceof SqsClient)` → drop
- L327 `PossiblyNullPropertyAssignmentValue` — `$this->workTimeout = $seconds` (int prop, ?int setter) → make prop `?int`

### `src/Worker/AbstractWorker.php` — 10
- L95 `PossiblyNullPropertyAssignmentValue` — `$this->logger = $log` nullable → set only if non-null (`if ($log)`), or make prop `?LoggerInterface` (then the isset guards below are still valid)
- L114/124/134 `RedundantCast` — drop `(string)`/`(int)` casts
- L150/160/170 `RedundantPropertyInitializationCheck` — `isset($this->logger)` on non-nullable prop → decide with L95 (nullable prop + keep isset, or always-set + drop isset)
- L296 `PossiblyUndefinedIntArrayOffset` ×2 — `$job[0]`/`$job[1]` → shape-assert after `is_array($job)`
- L299 `UnusedVariable` — inline the `$ack` assignment

### `src/Adapter/Beanstalk.php` — 7
- L50 `RedundantCondition` ×2 — drop `&& $this->client`
- L121 `TypeDoesNotContainType` ×2 — `$result` string|true vs `is_array` → assert string then decode, or align `Client::stats` return shape
- L211 `InvalidArgument` — `Client::reserve` param → widen to `int|null` (fixes Client L107)
- L213 `PossiblyUndefinedStringArrayOffset` ×2 — `$result['id']`/`['body']` → shape-assert from `reserve`

### `src/Adapter/IO/StreamIO.php` — 7
- L110/117/140 `NoValue` — ctor docblocks `@param null $context`/`$read_write_timeout` type both as null → `@param resource|array|null $context`, `@param int|null $read_write_timeout`
- L175 `UnusedFunctionCall` — `stream_set_chunk_size` → use its result or restructure
- L184/231 `MoreSpecificImplementedParamType` — literal `@psalm-param 4`/`@psalm-param int<1, max>` docblocks → replace with `int` (matches `AbstractIO`)
- L313 `PossiblyInvalidArgument` — `$t->getCode()` int|string → `(int)`

### `src/Worker/Amazon/SNS/Application/PlatformEndpoint/Remove.php` — 4
- L65 `PossiblyNullArgument` — `@unserialize($payload)` → guard `is_string($payload)`
- L144 `ArgumentTypeCoercion` — `onSuccess($message)` → instanceof concrete `Remove`
- L146 `TypeDoesNotContainType` ×2 — `$delSuccess` inferred all-false → restructure to early returns

### `src/Worker/Guzzle.php` — 4
- L60 `PossiblyNullArgument` — `@unserialize($payload)` → guard `is_string`
- L95/100 `MissingDocblockType` — `/** @var $fulfilledResponse … */` misplaced var → `/** @var \GuzzleHttp\Psr7\Response $fulfilledResponse */` (same for rejected)
- L96 `PossiblyFalseOperand` — `json_encode(...)` → `JSON_THROW_ON_ERROR` or cast

### `src/Adapter/AbstractAdapter.php` — 3
- L116/126/136 `RedundantPropertyInitializationCheck` — `isset($this->logger)` on non-nullable prop → make `protected ?LoggerInterface $logger = null;` and keep isset (adapters don't set it in ctor)

### `src/Message/Guzzle.php` — 3
- L51 `MoreSpecificReturnType` + L58/61 `LessSpecificReturnStatement` — `getRequest(): Request` too specific → return `Psr\Http\Message\RequestInterface`

### `src/Worker/…/PlatformEndpoint/Publish.php` — 3
- L64 `PossiblyNullArgument` — guard `is_string($payload)` (shared payload fix)
- L108 `ArgumentTypeCoercion` — `onFailure($message, …)` → instanceof concrete `Publish`; `getAwsErrorCode() ?? ''`

### `src/Worker/…/PlatformEndpoint/Register.php` — 3
- L61 `PossiblyNullArgument` — guard `is_string($payload)`
- L135 `PossiblyUndefinedVariable` — init `$endpointResult = null` before try; `ArgumentTypeCoercion` for `onSuccess($message)` → instanceof concrete `Register`

### `src/Adapter/Redis/Connector.php` — 2
- L41 `PossiblyUndefinedStringArrayOffset` — `$config['queue'] ?? $this->connection`
- L43 `PossiblyNullArgument` — `retry_after` null → `(int)($config['retry_after'] ?? 0)`

### `src/Logger.php` — 2
- L36 `PossiblyFalseOperand`/`InvalidOperand` — `getmypid()` → `(string) getmypid()`

### `src/Worker/Amazon/SNS/Application.php` — 2
- L19 `MissingDocblockType` + L24 `UndefinedDocblockClass` — see order-of-attack #2; fixes the `$snsClient` cascade

### `src/Worker/…/PlatformEndpoint.php` — 1
- L48 `PossiblyFalseOperand` — `strrpos(...)` false-able → guard or `?? ''`

### `src/Publisher/AbstractPublisher.php` — 1
- L63 `RedundantCast` — drop `(string)`

### `src/Worker/Serialized.php` — 1
- L57 `PossiblyNullArgument` — guard `is_string($payload)` (shared payload fix)

## Done when
1. Full psalm run reports **0 errors** (info issues may remain).
2. `--stats` inference % recorded (see the inference plan).
3. Full sweep green in the container: `app-code-quality`, PHPUnit suite,
   `php -l` on touched files, `phpcbf` idempotent.