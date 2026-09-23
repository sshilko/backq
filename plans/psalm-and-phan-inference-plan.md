# Psalm Error Fix + Phan Inference Plan

- Status: **Psalm portion complete** (2026-09-23, branch `psalm-error-fixes`):
  all **147 errors fixed** with typing/code only (never suppressions) — 0 errors
  at `errorLevel=3`, `--stats` re-measured at **92.3495%** (baseline 88.3122%).
  The Phan-quality work in §5 is not started.
- Purpose: enumerate **every Psalm error (147)** with a concrete, one-at-a-time
  fix so a follow-up agent can execute them 1-by-1, and maximise Phan
  type-inference quality where the same lines allow it.
- Baseline (Sep 2026, `backq.php83` container): Psalm **147 errors**,
  `Psalm was able to infer types for 88.3122% of the codebase`; Phan **189
  issues / 37 issue families** (Phan has no aggregate `%` — see §5 for the
  metric proxies).
- Supersedes: `plans/psalm-error-fix-plan.md` (stale **145-error** count) and
  extends `plans/psalm-inference-improvement-plan.md` (Psalm-% goal) with the
  Phan quality goal. Fixing an error here is the same line that scores as
  `mixed` in the inference plan, so the two goals move together.
- Hard constraint: `build/psalm.xml` sets `errorLevel="3"`, `disableSuppressAll="true"`,
  `strictBinaryOperands`, `ensureArrayIntOffsetsExist`, `ensureArrayStringOffsetsExist`.
  **`@psalm-suppress` is ignored.** Every finding is fixed with typing, real code,
  or docblock changes — never suppressions.

## 1. Reproduce

Everything runs inside the `backq.php83` docker container (repo mounted at `/app`).

### Psalm — full project (the gate that is red)

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --no-diff --disable-extension=xdebug"
```

`composer app-psalm` is the same command plus `--show-info=true --long-progress --stats`.
Exit code **2** (red) today because of the 147 errors. `--stats` gives the per-file
`% inferred` table used by the inference plan.

### Psalm — single file fast loop (use while iterating)

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --no-diff --disable-extension=xdebug src/Adapter/Nsq.php"
```

### Phan — full project

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/phan \
  --allow-polyfill-parser --color -k ./build/phan.php"
```

Phan is slow/memory-heavy (~10k elements); run it in the background and read the
output file, or add `> /tmp/phan.txt 2>&1` and tail it.

### Per-fix workflow (AGENTS.md)

For each touched file, in order, then finish with the full sweep:

1. `php -l <file>` — syntax check (won't catch redundant-union fatals; see §4.6)
2. `php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s <file> --report=full`
   (always `--no-cache`)
3. `php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon <file>`
   — also run it on the **changed file alone** to reproduce the pre-commit hook's
   file set (child-width/narrowing quirks, see AGENTS.md)
4. Psalm single-file (above), expect that file green
5. Done-when: full psalm 0 errors, full `composer app-code-quality` green, PHPUnit
   suite green (`php ./vendor/bin/phpunit --configuration=phpunit.xml`), `phpcbf`
   `--no-cache src tests` = "No violations were found".

`src/Adapter/Beanstalk.php` and `src/Adapter/Beanstalk/Client.php` carry class-level
`@phpcs:disable` — phpcs/phpcbf skip them; keep them correct via `php -l` + tests +
psalm, and remember redundant union types there are still PHP fatals.

## 2. Quick wins (already validated)

Run in a scratch step; review the diff before keeping:

```
docker exec backq.php83 bash -c "cd /app && php ./vendor/bin/psalm.phar \
  --php-version=\$(php -r 'echo PHP_VERSION;') --config build/psalm.xml \
  --memory-limit=-1 --alter --issues=MissingOverrideAttribute,UnusedVariable --dry-run"
```

Validated on 2026-09-23 (dry-run):

- `MissingOverrideAttribute` → inserts `#[\Override]` on the 4 anonymous-class
  methods in `src/Adapter/Redis.php:119-134` — correct as-is.
- `UnusedVariable` → removes dead initializers `$redisJob = null;`
  (`Redis.php:418`), `$ack = false;` (`AbstractWorker.php:299`), `$messageBody
  = null;` (in `DynamoSQS.php`, see §4.4) — correct.
- **Do NOT pass `ClassMustBeFinal`** to `--alter`: it corrupts files, e.g.
  `<?php` → `final <?php` and `Copyright (c) 2013-2026` → `Copyfinal right …`
  (verified in the dry-run diff). Any `final`-class mass change must be typed by
  hand and reviewed (and it is a BC concern — most workers are deliberately
  extendable).

The earlier "36 auto-fixable" estimate included `ClassMustBeFinal` issues; only the
`MissingOverrideAttribute` (4) + `UnusedVariable` (4) subsets are safe to auto-apply.

## 3. The 147 errors, by file

Format per entry: `file:line` — `Code — "message"` → **Fix**. Messages are
transcribed from the 2026-09-23 full run (stored at `tmp/psalm-report.txt`,
inventory at `tmp/psalm-inventory.txt`).

---

### 3.0 Shared root causes (fix first — they cascade)

- **Payload channel (kills ~20 errors across workers).** `AbstractWorker::work()`
  yields `mixed|null`; every worker's `@unserialize($payload)` is then
  `mixed|false|null`. Add `if (!is_string($payload)) { $work->send(true); continue; }`
  before `@unserialize($payload)` in: AProcess, Guzzle, Serialized,
  Publish/Register/Remove workers. This widens the whole class of
  `PossiblyNullArgument`/`PossiblyNullReference`.
- **Logger property (kills 10 errors).** `protected LoggerInterface $logger;`
  (non-nullable, assigned only via `setLogger`) → in both `AbstractAdapter` and
  `AbstractWorker`:
  `protected ?LoggerInterface $logger = null;` — fixes
  `RedundantPropertyInitializationCheck` (§4.1, §4.10) and
  `PossiblyNullPropertyAssignmentValue` (§4.10) in one move; the `isset()` guards
  are then meaningful.
- **StreamIO ctor docblocks (kills 5 errors in 2 files).** `@param null
  $read_write_timeout` and `@param null $context` → real types, see §4.5.
- **Beanstalk vendored `Beanstalk\Client` docblocks are wrong** (they exclude
  `array` from `stats()`/`statsTube()` returns). Fix centrally with a Psalm
  `<stubs>` file (see §4.3) — this is the root cause of the Beanstalk cluster
  (L216, Beanstalk.php L121/L113).

---

### 4.1 `src/Adapter/AbstractAdapter.php` — 3

- `116:13`, `126:13`, `136:13` — `RedundantPropertyInitializationCheck —
  "Property $this->logger with type Psr\Log\LoggerInterface should already be set
  in the constructor"` → make the property nullable (`protected
  ?LoggerInterface $logger = null;`) and keep the `isset($this->logger)` guards
  in `logInfo`/`logDebug`/`logError`. (§3 shared root cause.)

### 4.2 `src/Adapter/Beanstalk.php` — 7

- `50:13`, `50:42` — `RedundantCondition — "Type BackQ\Adapter\Beanstalk\Client
  for $this->client is never falsy"` / `"Operand … is always truthy"` → drop the
  `&& $this->client` part of the guard:
  `if (true === $this->connected) { return true; }` (`$client` is a non-nullable
  promoted property).
- `121:25`, `121:36` — `TypeDoesNotContainType — "Type non-falsy-string|true for
  $result is never array<array-key, mixed>"` → psalm models the vendored
  `Beanstalk\Client::stats()` return as `string|false`. After §4.3's stub it is
  `array<string,mixed>|string|false`, and this check becomes legal. Until the
  stub lands, restructure to avoid the array test, e.g. call
  `statsTube($queue)` (already handled via the same fix at L113) or
  `assert(is_array($result))`.
- `211:50` — `InvalidArgument — "Argument 1 … reserve expects null, but int|null
  provided"` → fixed by widening `Client::reserve`'s docblock param to
  `int|null` (§4.3).
- `213:29`, `213:44` — `PossiblyUndefinedStringArrayOffset — "Possibly undefined
  array offset 'id'/'body' …"` → fixed by giving `Client::reserve` a shaped
  return `@return array{id: string, body: string}|false` (§4.3).

### 4.3 `src/Adapter/Beanstalk/Client.php` — 24

Recommended root-cause step: add a Psalm **stub** for the vendored client. Create
`build/psalm-stubs/beanstalk-client.php`. A Psalm `<stubs>` entry **replaces** the
class definition for analysis, so the stub must declare every property and method
that our subclass or the adapter relies on — list the full surface below
(verified against `vendor/davidpersson/beanstalk/src/Client.php`:

```php
<?php

namespace Beanstalk;

use Psr\Log\LoggerInterface;

class Client
{
    public bool $connected = false;

    /**
     * @var array{host: string, logger: ?LoggerInterface, persistent: bool, port: int, timeout: int}
     */
    protected array $_config = [];

    /** @var resource|null */
    protected $_connection = null;

    public function __construct(array $config = []) {}

    public function __destruct() {}

    /** @return bool */
    public function connect() {}

    /** @return bool */
    public function disconnect() {}

    /** @param string $message */
    protected function _error($message) {}

    /** @param string $data @return int */
    protected function _write($data) {}

    /** @param int|null $length @return string|false */
    protected function _read($length = null) {}

    /** @param int $pri @param int $delay @param int $ttr @param string $data @return string|false */
    public function put($pri, $delay, $ttr, $data) {}

    /**
     * @param int|null $timeout
     * @return array{id: string, body: string}|false
     */
    public function reserve($timeout = null) {}

    /** @param int|string $id @return bool */
    public function delete($id) {}

    /** @param int|string $id @param int $pri @param int $delay @return bool */
    public function release($id, $pri, $delay) {}

    /** @param int|string $id @param int $pri @return bool */
    public function bury($id, $pri) {}

    /** @param int|string $id @return bool */
    public function touch($id) {}

    /** @param string $tube @return bool */
    public function watch($tube) {}

    /** @param string $tube @return bool */
    public function ignore($tube) {}

    /** @param int|string $id @return array|false */
    public function peek($id) {}

    /** @param int $bound @return int|false */
    public function kick($bound) {}

    /** @param int|string $id @return array|false */
    public function kickJob($id) {}

    /** @param int|string $id @return array|false */
    public function statsJob($id) {}

    /**
     * @param string $tube
     * @return array<string, mixed>|string|false
     */
    public function statsTube($tube) {}

    /** @return array<string, mixed>|string|false */
    public function stats() {}

    /** @return array|false */
    public function listTubes() {}

    /** @return string|false */
    public function listTubeUsed() {}

    /** @return array|false */
    public function listTubesWatched() {}

    /**
     * @param bool $decode
     * @return array<string, mixed>|string|false
     */
    protected function _statsRead($decode = true) {}

    /** @param string $data @return array<string, int|float|string> */
    protected function _decode($data) {}
}
```

and register it in `build/psalm.xml`:

```xml
<psalm>
    <stubs>
        <file name="build/psalm-stubs/beanstalk-client.php" />
    </stubs>
</psalm>
```

Rationale: the vendored docblocks under-declare `stats()`/`statsTube()` (they
return the decoded `array` at runtime, plus `string|false`), which is exactly why
the adapter checks `is_array($result)`. Because the stub **replaces** the class,
correct **covariance** applies to our overrides — any child override return type
must be a subtype of the stub's. So alongside the stub, our `Client` must:

- `reserve()`: narrow `@return array|false` → `@return array{id: string, body:
  string}|false` (also fixes Beanstalk.php L213 offsets directly).
- `statsTube()`: narrow `@return array|string|bool` → `@return array<string,
  mixed>|string|false` (child `array` ≈ `array<array-key, mixed>` is too broad vs
  the stub's `array<string, mixed>`).
- Delete our `_statsRead($decode = '')` override entirely (see L224 entry) so the
  stub's `_statsRead(bool $decode = true)` contract is inherited.

The stub is the smallest diff; the alternative (no stub) forces per-site
workarounds plus the L216 mismatch can only be silenced by widening inside
`src/` — not possible without editing `vendor/`.

Per-error detail (order matters; several are fixed by earlier ones):

- `48:16` — `ImplementedReturnTypeMismatch — "The inherited return type 'null'
  … is different to the implemented return type 'null|true'"` → `__destruct()`:
  change `/** @return null|true */` to `/** @return null */` and `return true;`
  → `return;` (persistent case keeps skipping `disconnect()` but the return value
  is void anyway).
- `54:17`, `79:13`, `85:17`, `86:17`, `91:17` — `PossiblyUndefinedStringArrayOffset
  — "'persistent'/'timeout'/'host'/'port' …"` → covered by the stub's `_config`
  shape (above).
- `88:17` — `InvalidArgument — "Argument 4 of IO\StreamIO::__construct expects
  null, but 2 provided"` → **not** a call-site bug: `StreamIO`'s docblock types
  arg 4 as `null`. Fixed in §4.5 (StreamIO ctor docblocks).
- `107:29` — `MoreSpecificImplementedParamType — "reserve … more specific type
  'null', expecting 'int|null'"` → `reserve($timeout = null)` docblock
  `@param null $timeout …` → `@param int|null $timeout` (matches vendored
  contract; stub already declares it).
- `114:25`, `122:25` — `PossiblyNullReference — "Cannot call method
  stream_set_timeout on possibly null value"` → `$this->_io` is
  `?IO\StreamIO`; `reserve()` uses it without a guard. Add after the connected
  check:
  `if (!$this->connected || null === $this->_io) { throw new RuntimeException('Not connected'); }`
  (or reuse the existing connect-then-use invariant with an `assert`).
- `115:62` — `NoValue — "All possible types for this argument were invalidated …
  This may be dead code"` → once `reserve`'s param is `int|null` (L107), the
  `isset($timeout)` branch becomes reachable and the `sprintf` arg is alive. No
  further change.
- `133:26` — `InvalidScalarArgument — "Argument 1 of strtok expects string, but
  bool|string provided"` → `$readio = $this->_read();` can be `false`; guard
  before `strtok`: `if (false === $readio) { return false; }` (mirrors the
  vendored `_read` contract).
- `168:17`, `185:17` — `UnevaluatedCode — "Expressions after
  return/throw/continue"` → remove the dead `break;` after the `return [...]` in
  the `RESERVED` case and after `return false;` in `TIMED_OUT`.
- `188:62` — `PossiblyFalseOperand — "Cannot concatenate with a possibly false
  false|string"` → `$status = strtok(...)` may be `false`; cast:
  `$this->_error(__FUNCTION__ . " status = '" . (string) $status . "', …"` or
  assert `is_string($status)` first.
- `216:16` — `ImplementedReturnTypeMismatch — "The inherited return type
  'bool|string' … is different to the implemented return type 'array<array-key,
  mixed>|bool|string'"` → once the stub is registered, the parent `statsTube`
  returns `array<string,mixed>|string|false`; narrow our override's docblock to
  exactly that (`@return array<string, mixed>|string|false`) so child ≤ parent
  (covariance).
- `224:34` — `InvalidArgument — "Argument 1 … _statsRead expects bool, but
  non-empty-string provided"` → **delete** our `_statsRead($decode = '')`
  override (L290-305). It reinterprets the parent's `bool $decode` as a log
  prefix string — a semantic override that is not LSP-compatible. Simplify
  `statsTube()` to `return $this->_statsRead();` so the inherited stub contract
  (`_statsRead(bool $decode = true): array<string, mixed>|string|false`) runs.
  This also removes the L293/299/301 findings, which live only inside the deleted
  override. Runtime behavior is unchanged: the vendored implementation decodes
  when `$decode` is truthy, exactly what `statsTube()`/`stats()` want.
- `235:21` — `PossiblyNullReference — "Cannot call method write on possibly null
  value"` → `_write()`: guard `$this->_io` (same pattern as L114).
- `257:39` — `PossiblyNullReference — "Cannot call method stream_get_contents on
  possibly null value"` → `_read($length)`: guard `$this->_io` before use.
- `278:35` — `PossiblyNullReference — "Cannot call method stream_get_line on
  possibly null value"` → `_read()` fallback path: guard `$this->_io` before use.
- L293/299/301 (inside the deleted override) — **resolved by the L224 deletion**,
  no further work.

### 4.4 `src/Adapter/DynamoSQS.php` — 13

- `179:40` — `RedundantCast — "Redundant cast to int"` → `$this->workTimeout` is
  already `int` (see L327); drop `(int)` at `WaitTimeSeconds`.
- `185:61` — `PossiblyNullArgument — "Argument 1 of count cannot be null…"` →
  `$result->get('Messages')` is `mixed|null`. Capture and guard:
  `$messages = $result->get('Messages'); if ($result && $messages && is_array($messages) && count($messages) > 0)`.
- `186:32` — `PossiblyNullArrayAccess — "Cannot access array value on possibly
  null variable of type mixed|null"` → after the above guard,
  `$messagePayload = $messages[0];` (element is typed by the `@var` shape below).
- `188:13` — `UnusedVariable — "$messageBody is never referenced…"` → the inline
  assignment `is_array($messageBody = json_decode(...))` makes the earlier
  `$messageBody = null;` dead — drop that initializer line.
- `191:27`, `206:26` — `PossiblyNullArrayAccess` on `$messagePayload['Body']` /
  `['ReceiptHandle']` → give `$messagePayload` a shape after the guard:
  `/** @var array{Body: string, ReceiptHandle: string} $messages */` on the
  captured `$messages`, or add `is_array($messagePayload)` before dereferencing.
- `236:24` ×2, `236:37` — `PossiblyFalseOperand` / `InvalidOperand — "Cannot
  concatenate with a possibly false false|int / false|string"` →
  `crc32(getmypid() . gethostname())` both return false-able values →
  `crc32((string) getmypid() . (string) gethostname())`.
- `241:54` — `InvalidScalarArgument — "Argument 3 … QueueTableRow::__construct
  expects string, but int|mixed provided"` → `$msgid` is `int` (crc32) or mixed
  param. Fix: `$msgid = (string) crc32((string) getmypid() . (string) gethostname());`
  and cast the param branch `(string) $params[self::PARAM_MESSAGE_ID]`.
- `247:25` — `PossiblyNullArrayAccess — "Cannot access array value on possibly
  null variable $response['@metadata'] of type mixed|null"` → guard:
  `$metadata = $response['@metadata'] ?? null; if ($response && is_array($metadata) && 200 === ($metadata['statusCode'] ?? null))`.
- `267:13` — `RedundantCondition — "Type SqsClient for $sqs is always
  SqsClient"` → the `assert($sqs instanceof SqsClient);` is redundant because the
  preceding `if ($this->sqsClient)` already guarantees the type — drop the assert.
- `327:30` — `PossiblyNullPropertyAssignmentValue — "$this->workTimeout with
  non-nullable declared type 'int' cannot be assigned nullable type 'int|null'"`
  → `setWorkTimeout(?int $seconds = null)`: either make the property `?int`
  (then L179's `(int)` cast becomes meaningful again — keep it) or guard:
  `if (null !== $seconds) { $this->workTimeout = $seconds; }` with the property
  staying `int` and L179 cast removed. **Pick one, not both.**

### 4.5 `src/Adapter/IO/StreamIO.php` — 7 (root-cause file)

- `110:21`, `117:21`, `140:50` — `NoValue — "All possible types for this argument
  were invalidated — This may be dead code"` → the ctor docblocks type two params
  as literal `null`:
  - `@param null $read_write_timeout` → `@param int|null $read_write_timeout`
  - `@param null $context` → `@param resource|array|null $context`
  This revives the `if ($context)` TLS branch (L110/117) and the
  `stream_set_timeout` call (L140), and fixes `Beanstalk/Client.php:88` too.
- `175:9` — `UnusedFunctionCall — "The call to stream_set_chunk_size is not
  used"` → capture its return: add `private int $chunkSize = 0;` and
  `$this->chunkSize = stream_set_chunk_size($this->sock, 1024);` (it returns the
  previous chunk size).
- `184:30` — `MoreSpecificImplementedParamType — "… more specific type '4',
  expecting 'int'"` → remove the `@psalm-param 4 $n` docblock on `read(int $n)`;
  the override must accept the full `int` contract from `AbstractIO::read`.
- `231:44` — `MoreSpecificImplementedParamType — "… more specific type 'int<1,
  max>', expecting 'int'"` → remove the `@psalm-param int<1, max>
  $read_write_timeout` docblock on `stream_set_timeout(int $read_write_timeout)`
  (align to the parent's plain `int`).
- `313:58` — `PossiblyInvalidArgument — "Argument 2 … RuntimeException::__construct
  expects int, but possibly different type int|string provided"` →
  `$t->getCode()` returns `int|string` → pass `(int) $t->getCode()`.

### 4.6 `src/Adapter/Nsq.php` — 18

- `122:37`, `122:59` (3 findings: 2× `PossiblyFalseOperand` + 1×
  `InvalidOperand — "Cannot concatenate with a possibly false false|string /
  false|int"`) →
  `$this->config['clientId'] = (string) gethostname() . '_' . (string) getmypid();`
- `136:54` — `RedundantCast — "Redundant cast to int<1000, max>"` → inside
  `if ($seconds >= 1)`, `$seconds` is already `int<1,max>` →
  drop the `(int)` on `($seconds * 1000)`.
- `156:29`, `591:21`, `645:31` — `PossiblyNullReference — "Cannot call method
  close/write/read on possibly null value"` → `$this->_io` is nullable; after
  `connect()` succeeds assert it once:
  `assert($this->_io instanceof IO\StreamIO);` (or a null-check that returns
  early) before `close()`, `write()`, `read()`.
- `322:27` ×2, `325:31` ×2, `634:16` ×2 — `PossiblyUndefinedIntArrayOffset` /
  `PossiblyInvalidArrayAccess — "unpack … offset '1' … non-array variable of type
  false"` → extract a guarded helper and use it at all three sites:
  ```php
  /**
   * @param "J"|"N"|"n" $format
   * @return array<string, int>
   */
  private function unpackInt(string $format, string $data): array
  {
      $result = unpack($format, $data);
      if (false === $result || !isset($result[1])) {
          throw new RuntimeException('Failed to unpack frame data');
      }
      return $result;
  }
  ```
  Then L322: `$time = floor(unpackInt("J", substr($messageFrame, 0, 8))[1] / 1000000000);`,
  L325: `'attempts' => unpackInt("n", substr($messageFrame, 8, 2))[1],`,
  L634: same with `"N"`. Alternatively keep inline with
  `$t = unpack(...); if (false === $t) { throw … } $u = $t[1] ?? 0;`.
- `326:31` — `InvalidClass — "Class, interface or enum Datetime has wrong
  casing"` → `Datetime` → `DateTime` (ensure `use DateTime;`).
- `326:63` — `InvalidScalarArgument — "Argument 2 of Datetime::createFromFormat
  expects string, but float provided"` → `(string) $time`; also handle the
  possible `false` from `createFromFormat`:
  `$dt = DateTime::createFromFormat("U", (string) $time); if (false === $dt) { throw new RuntimeException('Bad time'); }`.
- `370:139` — `InvalidOperand — "Cannot concatenate with a float"` → the operand
  is `$this->config['heartbeat_interval_ms'] * self::HEARTBEAT_TTR_RATION` (float)
  concatenated into the message → cast before concat:
  `(int) ($this->config['heartbeat_interval_ms'] * self::HEARTBEAT_TTR_RATION)`.
- `467:13` — `PossiblyFalseArgument — "Argument 2 … writeCommandWithBody cannot
  be false…"` → `json_encode($identify)` → `json_encode($identify, JSON_THROW_ON_ERROR)`
  (throws instead of returning false; return type becomes `string`).
- `488:13` — `PossiblyUndefinedStringArrayOffset — "Possibly undefined array
  offset 'auth_required' …"` → shape the decoded features:
  `/** @var array{auth_required: bool} $features */` after the `json_decode`, or
  guard `$features['auth_required'] ?? false`.

### 4.7 `src/Adapter/Redis.php` — 23

- `64:5` — `InvalidDocblock — "[]\Illuminate\Queue\Jobs\RedisJob is not a valid
  type (Unexpected token [ …)"` → `/** @var []\Illuminate\Queue\Jobs\RedisJob */`
  → `/** @var RedisJob[] $reservedJobs */` (or `array<string, RedisJob>` — the
  offsets used are job-id strings).
- `119:17`, `124:17`, `129:17`, `134:17` — `MissingOverrideAttribute` on the
  anonymous `ExceptionHandler` methods → add `#[Override]` to `report`, `render`,
  `renderForConsole`, `shouldReport` (auto-fix safe, §2).
- `170:31` — `RedundantCast — "Redundant cast to int"` → `$this->read_timeout`
  is already `int`; `$newWorkTimeout = $this->read_timeout - 1;`.
- `172:50`, `175:50` — `InvalidOperand — "Cannot concatenate with a int|null"` →
  `$seconds` is the `?int` setter param; in the log strings use
  `(string) ($seconds ?? 0)` or `$seconds ?? 0`.
- `197:25` — `RedundantCondition — "Operand of type … Manager is always truthy"`
  → `$this->queue` is a non-nullable `Manager`; drop `$this->queue &&` from the
  L197 condition (keep `$redisQueue = $this->queue->getConnection(...)`).
- `198:49` — `UndefinedInterfaceMethod — "Method … Queue::getRedis does not
  exist"` → `getConnection()` returns the `Queue` **interface**; the concrete
  instance is our `BackQ\Adapter\Redis\Queue`. Add
  `assert($redisQueue instanceof Queue);` before `getRedis()` (same assert that
  already exists at L253 for the other call site).
- `200:39`, `219:39` — `UndefinedMagicMethod — "Magic method
  RedisManager::isconnected/disconnect does not exist"` → illuminate's
  `RedisManager` forwards these via `__call` only when `->connection()` etc. are
  misspelled/magic. Replace with the real API:
  `$manager->connection()->client()->isConnected()` and
  `$manager->connection()->disconnect()` (both methods are concrete on
  `Illuminate\Redis\Connections\Connection` / phpredis).
- `251:13`, `251:33` — `RedundantCondition — "$this->queue is never falsy"` →
  drop `&& $this->queue` from the `ping()` guard (same as L197).
- `258:17` ×2 — `TypeDoesNotContainType — "string does not contain true"` /
  `"Type string for $pong is never !true"` → phpredis `ping()` returns a string;
  drop the `true === $pong` arm:
  `if ('+PONG' === $pong) { … }`.
- `413:21` — `RedundantCondition — "Operand of type true is always truthy"` and
  `421:17` — `TypeDoesNotContainType — "Operand of type 0 is always falsy"` →
  `self::BLOCKFOR_EMULATE` is a `const 0`, so psalm constant-folds both branches.
  Break the folding with an indirection variable once at the top of `pickTask()`:
  `$emulate = 0 !== self::BLOCKFOR_EMULATE;` then use `$emulate` at L413
  (`if (!$emulate)`) and L421 (`if ($emulate)`).
- `418:13` — `UnusedVariable — "$redisJob is never referenced…"` → the
  `$redisJob = null;` initializer is dead (both branches assign) → remove it
  (auto-fix safe, §2).
- `450:27`, `456:17` — `PossiblyNullArrayOffset — "…using possibly null offset
  null|string"` → `$redisJob->getJobId()` returns `null|string`; capture and
  guard: `$jobId = $redisJob->getJobId(); if (null === $jobId) { throw new
  RuntimeException('Job without id'); }` then use `$jobId` for the
  `isset`/`$this->reservedJobs[$jobId]` and the return array.
- `471:21` — `PossiblyUndefinedStringArrayOffset — "Possibly undefined array
  offset 'data' …"` → `$redisJob->payload()['data']` → capture
  `$payload = $redisJob->payload();` and `$data = $payload['data'] ?? null;`
  (shape `/** @var array{data: string} $payload */` if you need strictness).
- `575:17` — `InvalidArgument — "Argument 1 of RedisManager::__construct expects
  Illuminate\Contracts\Foundation\Application, but Illuminate\Container\Container
  provided"` → `$this->app` is a `Redis\App extends Container`; the swappable
  `Application` contract lives on the container. Type a local view before the
  call: `/** @var \Illuminate\Contracts\Foundation\Application $app */
  $app = $this->app; return new RedisManager($app, …);` (or make `Redis\App`
  implement the `Application` interface if the container can provide it — check
  runtime resolvers before changing the class hierarchy).

### 4.8 `src/Adapter/Redis/Connector.php` — 2

- `41:13` — `PossiblyUndefinedStringArrayOffset — "Possibly undefined array
  offset 'queue' …"` → `$config['queue']` → give a default:
  `$config['queue'] ?? 'default'` (matches `Illuminate\Queue\RedisQueue`'s
  default queue name).
- `43:13` — `PossiblyNullArgument — "Argument 4 … Queue::__construct cannot be
  null…"` → `$config['retry_after'] ?? null` → `$config['retry_after'] ?? 60`
  (the RedisQueue default); keep `block_for ?? null` (it is nullable).

### 4.9 `src/Logger.php` — 2

- `36:64` ×2 — `PossiblyFalseOperand` / `InvalidOperand — "Cannot concatenate
  with a possibly false false|int"` → `getmypid()` may be `false` →
  `(string) getmypid()` in the `fwrite` line.

### 4.10 `src/Message/Guzzle.php` — 3

- `51:35` — `MoreSpecificReturnType — "The declared return type
  'GuzzleHttp\Psr7\Request' is more specific than the inferred return type
  'Psr\Http\Message\RequestInterface'"` together with:
- `58:20`, `61:16` — `LessSpecificReturnStatement — "The type
  'Psr\Http\Message\RequestInterface&static' / 'Psr\Http\Message\RequestInterface'
  is more general than the declared return type 'GuzzleHttp\Psr7\Request'"` →
  widen the method contract: `getRequest(): Psr\Http\Message\RequestInterface`
  (import the interface; consumers such as `Worker/Guzzle.php` only use
  `sendAsync($request)` which accepts the interface).

### 4.11 `src/Publisher/AbstractPublisher.php` — 1

- `63:28` — `RedundantCast — "Redundant cast to string"` → `setQueueName(string
  $string)` → drop the cast: `$this->queueName = $string;`.

### 4.12 `src/Worker/AbstractWorker.php` — 10

- `95:25` — `PossiblyNullPropertyAssignmentValue — "$this->logger … cannot be
  assigned nullable type"` → make the property `?LoggerInterface` (shared fix §3).
- `114:28` — `RedundantCast — "Redundant cast to string"` → `setQueueName(string
  $string)` → `$this->queueName = $string;`
- `124:35`, `134:30` — `RedundantCast — "Redundant cast to int"` →
  `setRestartThreshold(int $int)` / `setIdleTimeout(int $int)` → drop `(int)`.
- `150:13`, `160:13`, `170:13` — `RedundantPropertyInitializationCheck` on
  `isset($this->logger)` → resolved by the nullable property (shared fix §3).
- `296:36`, `296:47` — `PossiblyUndefinedIntArrayOffset — "…offset '0'/'1' …"` →
  after `is_array($job)`, narrow the shape:
  `/** @var array{0: int|string, 1: string} $job */` (before the `yield
  $job[0] => $job[1];`), or capture `$jobId = $job[0] ?? null; $jobData = $job[1]
  ?? null;`.
- `299:17` — `UnusedVariable — "$ack is never referenced…"` → the `$ack = false;`
  initializer is dead (both branches assign) → drop it (auto-fix safe, §2).

### 4.13 `src/Worker/Amazon/SNS/Application.php` — 2

- `19:5` — `MissingDocblockType — "Misplaced variable"` →
  `/** @var $snsClient AwsSnsClient */` → `/** @var \BackQ\Worker\Amazon\SNS\SnsClient $snsClient */`
  (type before variable).
- `24:15` — `UndefinedDocblockClass — "… named AwsSnsClient does not exist"` →
  the real class is `BackQ\Worker\Amazon\SNS\SnsClient`; fix `@param AwsSnsClient`
  → `@param \BackQ\Worker\Amazon\SNS\SnsClient $awsSnsClient`. (These two cascade
  into undeclared-method findings in Phan too — same fix.)

### 4.14 `src/Worker/Amazon/SNS/Application/PlatformEndpoint.php` — 1

- `48:41` — `PossiblyFalseOperand — "Left operand cannot be falsable, got
  false|int"` → `strrpos($this->queueName, '_') + 1` — `strrpos` may be `false`;
  guard:
  ```php
  $underScore = strrpos($this->queueName, '_');
  $queue = false === $underScore ? $this->queueName : substr($this->queueName, $underScore + 1);
  ```

### 4.15 `src/Worker/Amazon/SNS/Application/PlatformEndpoint/Publish.php` — 3

- `64:45` — `PossiblyNullArgument — "Argument 1 of unserialize cannot be null…"`
  → shared payload guard: `if (!is_string($payload)) { $work->send(true);
  continue; }` before `@unserialize($payload)`.
- `108:50` — `ArgumentTypeCoercion — "Argument 1 … onFailure expects
  BackQ\Message\…\Publish, but parent type …PublishMessageInterface provided"` →
  the override's param is the concrete message; widen the subclass signature to
  the interface:
  `protected function onFailure(PublishMessageInterface $message, string $awsErrorCode): void`
  (matches the base contract; psalm accepts the interface at the call site).
- `108:60` — `PossiblyNullArgument — "Argument 2 … cannot be null…"` →
  `$e->getAwsErrorCode()` is `null|string`; guard before calling:
  `$code = $e->getAwsErrorCode(); if (null === $code) { $code = ''; } $this->onFailure($message, $code);`
  (the `if ($e->getAwsErrorCode())` already gates entry — capture the code once).

### 4.16 `src/Worker/Amazon/SNS/Application/PlatformEndpoint/Register.php` — 3

- `61:47` — `PossiblyNullArgument — "Argument 1 of unserialize cannot be null…"`
  → shared payload guard (`is_string($payload)` + `continue`).
- `135:52` — `PossiblyUndefinedVariable — "Possibly undefined variable
  $endpointResult defined in try block"` → initialize before the `try`:
  `$endpointResult = null;` (or restructure so the `try` result is assigned
  outside).
- `135:84` — `ArgumentTypeCoercion — "Argument 2 … onSuccess expects
  BackQ\Message\…\Register, but parent type …RegisterMessageInterface provided"`
  → widen `onSuccess(string $endpointArn, RegisterMessageInterface $message): bool`.

### 4.17 `src/Worker/Amazon/SNS/Application/PlatformEndpoint/Remove.php` — 4

- `65:45` — `PossiblyNullArgument — "Argument 1 of unserialize cannot be null…"`
  → shared payload guard (`is_string($payload)` + `continue`).
- `144:52` — `ArgumentTypeCoercion — "Argument 1 … onSuccess expects
  BackQ\Message\…\Remove, but parent type …RemoveMessageInterface provided"` →
  widen `onSuccess(RemoveMessageInterface $message): bool`.
- `146:25`, `146:26` — `TypeDoesNotContainType — "Operand of type false is always
  falsy"` / `"Type true for $delSuccess is never falsy"` → `onSuccess()` is
  declared to return `bool` but its body returns only `true`, so psalm narrows it
  to literal `true`. Either:
  - give the method a real bool result (`return true === $this->snsClient->deleteEndpoint(...)->get('…')`),
    or
  - keep the always-success contract and delete the dead `if (!$delSuccess)` block
    (mark the method `@return true`), or
  - annotate the local: `/** @var bool $delSuccess */ $delSuccess = $this->onSuccess($message);`.
  Prefer the `@var bool` + keeping the guard (least behavioral change).

### 4.18 `src/Worker/AProcess.php` — 16

Root cause: `$message` is `mixed|null` (from `@unserialize`) and its usable type
is established by a `$run = true` flag, which Psalm cannot correlate. Restructure
the narrow point:

- `94:44`, `103:43`, `121:74`, `122:50`, `126:54`, `135:51`, `136:51`, `137:51`,
  `150:51`, `151:51`, `152:51`, `153:51` — `PossiblyNullReference — "Cannot call
  method … on possibly null value"` → replace `if ($run) {` (L93) with
  `if ($run && $message instanceof \BackQ\Message\Process) {`. The `instanceof`
  narrows `$message` for the whole block **and** inside the closure defined in it
  (the closure captures the already-narrowed variable via `use ($message)`), which
  clears all 12 references at once.
- `150:41` — `PossiblyInvalidArgument — "Argument 1 of Process::__construct
  expects array<array-key, mixed>, but possibly different type
  array<array-key, mixed>|mixed|string provided"` → with `$message` narrowed, this
  is the *string/array* commandline branch. The `if (!is_array($cmd) &&
  is_string($cmd))` arm goes to `fromShellCommandline`; the `else` arm (used
  here) still has `getCommandline()` typed `array|string` (psalm doesn't
  correlate the earlier `is_array` check). Normalize before constructing:
  `$cmdLine = $message->getCommandline(); $cmdLine = is_array($cmdLine) ? $cmdLine : [$cmdLine];`
  and pass `$cmdLine` to `new Process(...)`.
- `184:33` — `RedundantCondition — "Type Process for $f is always Process"` → the
  `assert($f instanceof Process);` in the `foreach ($forks as $f)` is redundant
  once `$forks` is typed (`/** @var Process[] $forks */` … it is)
  → remove the assert.
- `209:45` — `UnusedVariable — "$ec is never referenced…"` → the `$ec = null;`
  after the trigger_error is dead (leftover cleanup) → remove it; also the earlier
  capture `$ec = $f->getExitCode();` is fine because it is read in the `if ($ec
  > 0)`.
- `236:25` — `TypeDoesNotContainType — "Type true for $processed is always
  !true"` → `$processed` starts `true` and is never reassigned to a falsy value on
  any reachable path. Annotate the variable at each assignment or restructure the
  exit logic:
  `/** @var bool $processed */ $processed = true;` (and the assignment in §4.16
  style) — or, if the check is genuinely always-true today, delete the
  `if (true !== $processed)` branch.

### 4.19 `src/Worker/Guzzle.php` — 4

- `60:47` — `PossiblyNullArgument — "Argument 1 of unserialize cannot be null…"`
  → shared payload guard (`is_string($payload)` + `continue`).
- `95:33`, `100:33` — `MissingDocblockType — "Misplaced variable"` →
  `/** @var $fulfilledResponse \GuzzleHttp\Psr7\Response */` →
  `/** @var \GuzzleHttp\Psr7\Response $fulfilledResponse */` (type first); same
  for `$rejectedResponse` → `/** @var \GuzzleHttp\Psr7\RequestException
  $rejectedResponse */`.
- `96:48` — `PossiblyFalseOperand — "Cannot concatenate with a possibly false
  false|string"` → `json_encode(...)` may be `false` →
  `json_encode((string) $fulfilledResponse->getBody(), JSON_THROW_ON_ERROR)`.

### 4.20 `src/Worker/Serialized.php` — 1

- `57:47` — `PossiblyNullArgument — "Argument 1 of unserialize cannot be null…"`
  → shared payload guard (`is_string($payload)` + `continue`).

---

## 5. Maximise Phan type-inference quality

**Goal:** raise the amount of code Phan can type-infer, wherever the Psalm fixes
(or independent typing) make it possible.

**Metric reality:** Phan ships no aggregate `% inferred` like Psalm's `--stats`
("Phan's analysis depth is not a single number"). Use these proxies, all read from
the full run (`php ./vendor/bin/phan --allow-polyfill-parser -k ./build/phan.php` > /tmp/phan.txt):

| Proxy | Baseline (2026-09-23) | Target |
|---|---|---|
| Total issues | 189 | ≤ 60 (doc-only `@throws` work alone clears ~35) |
| `PhanPartialTypeMismatch*` (ArgumentInternal 45 + Argument 10 + Return 1 + Property 1) | 57 | ~0 |
| `PhanTypeMismatch*` (all families + SuperType + ProbablyReal + Internal + ReturnSuperType) | ~23 | ~0 |
| `PhanPossiblyNonClassMethodCall` | 22 | ~0 |
| `PhanSuspiciousTruthy*` (String 7 + Condition 3) | 10 | ~0 |
| `PhanPossiblyNull*` / `PhanPossiblyFalseTypeArgument` / `PhanTypeArraySuspiciousNullable` | ~8 | ~0 |
| `PhanUndeclared*` (ClassMethod 3 + Method 1 + TypeParameter 1) | 5 | 0 |
| `PhanThrowTypeAbsent` + `ThrowsTypeAbsentForCall` | 35 | 0 (doc-only) |
| `PhanRedundantCondition` | 15 | 0 (same lines psalm flags) |

Because Phan cannot emit a %, treat **"maximise inference"** as: drive the
partial-mismatch / suspicion / undeclared families to zero, keep the total issue
count dropping, and never make a Phan finding worse to make Psalm better.

**Where the same lines fix both tools (do these first):**

1. **Payload channel** — `is_string($payload)` guards before `@unserialize`
   (§3) clear **~45** `PhanPartialTypeMismatchArgumentInternal` findings
   (Publish/Register/Remove/Guzzle/Serialized/AProcess all report
   `Argument 1 ($data) is … but \unserialize() takes string`).
2. **SNS client type** — `Application.php:19,24` (`$snsClient` typed as
   non-existent `AwsSnsClient`) causes
   `PhanUndeclaredClassMethod` (publish/createPlatformEndpoint/deleteEndpoint),
   `PhanTypeMismatchArgumentSuperType` and `PhanPossiblyNullTypeArgument` families.
   Fixing §4.13 collapses the SNS cluster in both tools.
3. **`onFailure`/`onSuccess` param widening** (§4.15–4.17) —
   `PhanTypeMismatchArgumentSuperType` + `PhanPossiblyNullTypeArgument` overlap.
4. **AProcess `$message` narrowing** (§4.18) — `PhanPossiblyNonClassMethodCall`
   and `PossiblyNullTypeArgument` in this file trace to the same `$run`-flag
   pattern; the `instanceof` restructure fixes both.
5. **`@throws` docblocks** — `PhanThrowTypeAbsent` (29) and
   `PhanThrowTypeAbsentForCall` (6) are doc-only but dominate the count and
   zero-cost: add `@throws \Exception`, `@throws RuntimeException`, etc. where the
   reported `throw` sites are. Best batch in one PR.
6. **`AbstractAdapter`/`AbstractWorker` contract typing** (the Psalm inference
   plan's Phase 1-2 docblocks) also remove `PhanCommentParamWithoutRealParam` (5),
   `PhanCommentParamOnEmptyParamList` (1), `PhanCommentDuplicateParam` (1) and
   several `PhanPartialTypeMismatchArgument` findings.

**Per-file Phan tracking** (baseline counts):

| File | Issues | Main gap |
|---|---|---|
| `Adapter/IO/StreamIO.php` | 48 | `$sock` resource/null, `stream_get_meta_data` keys, vendored docblocks |
| `Adapter/Nsq.php` | 28 | `$config` shape, unpack, datetime casing, `_io` |
| `Adapter/Beanstalk/Client.php` | 22 | vendored `Beanstalk\Client` contract (§4.3) |
| `Worker/AProcess.php` | 18 | `$message`/`$run` flag (§4.18) |
| `Adapter/Redis.php` | 11 | RedisManager magic, reservedJobs shape |
| `Adapter/DynamoSQS.php` | 10 | SQS `Result::get()`, msgid casts |
| `Worker/AbstractWorker.php` | 10 | logger nullability, `$job` shape |
| `Adapter/Beanstalk.php` | 6 | `$this->client`/stats return |
| `Message/Guzzle.php` | 6 | getRequest return type |
| `Worker/Amazon/SNS/SnsClient.php` | 6 | param signature vs AWS SDK (@method docs) |
| others | ≤ 22 across 10 files | payload guards, sns client, @throws |

`SnsClient.php` (6): its `publish(array $data) : mixed` etc. override the AWS SDK
`@method`-declared signatures with zero-arg defaults — align the overrides with
`array $args = []` and the SDK return types, or add matching `@method`
annotations.

**Measurement ritual:** after each fix batch run
`phan -k build/phan.php` to a file, grep
`PhanPartialTypeMismatch|PhanPossiblyNonClassMethodCall|PhanTypeMismatch|PhanSuspiciousTruthy|PhanUndeclared|PhanThrowTypeAbsent`
and record the new totals in the table above. Finish with the Psalm sweep — the
two gates must both be green (Psalm errors 0; Phan total shrinking, ideally ≤ 60).

## 6. Done when

1. Full Psalm run (single-file → full) reports **0 errors**; `--stats` shows the
   inference % re-measured and updated in `plans/psalm-inference-improvement-plan.md`.
   **DONE (2026-09-23): 0 errors, 92.3495% recorded.**
2. Phan total ≤ 60 with the partial-mismatch/suspicion/undeclared families ~0,
   `@throws` gaps closed.
3. Full sweep green inside `backq.php83`: `composer app-code-quality`,
   `php ./vendor/bin/phpunit --configuration=phpunit.xml` (full summary, exit 0),
   `phpcbf --no-cache src tests` = "No violations were found".
4. No `@psalm-suppress` anywhere new (it is disabled anyway) and no vendored files
   edited (stub in §4.3 or rename, never modify `vendor/`).
5. `UPGRADING` gainers: public API typing changes (adapter/worker contracts) get
   an `UPGRADING` entry per the repo convention.