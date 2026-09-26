# Plan 5 — Minimal `putTask()` contract, widened per adapter (Throwable result)

> Status: **implemented** — uncommitted in the working tree, not yet on a branch or PR.
> 452 tests / 1083 assertions green in `app-php83`.
> Scope: `BackQ\Adapter\AbstractAdapter::putTask()` reduced to one parameter, the whole
> `PARAM_*` array-key vocabulary deleted, `JOBTTR_DEFAULT` kept, `false` replaced by `Throwable`,
> and every adapter, publisher, worker, message, test and example adapted.
> **No backward compatibility.** 5.x is unreleased (latest tag `3.0.2`), the `$params` array form
> is removed outright rather than translated.
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`, `plan-4-minor-notes-behavior.md`.
>
> ### Where the implementation departed from the plan
>
> - `TestAdapter` widens `putTask()` with **all seven** shipped parameters
>   (`$readyWait`, `$jobTtr`, `$priority`, `$messageId`, `$jobId`, `$putAsDone`,
>   `$noSleep`) and records the ones the caller passed, so one double can assert
>   what `publish()` forwarded for any adapter. It has no variadic tail, which is
>   what lets `testPutTaskRejectsRetiredKeyName` observe the engine rejecting a
>   retired name.
> - `ThrowingPutTaskAdapter` had to repeat that widened signature: a child cannot
>   narrow a signature it inherits, and PHP has no way to say "same params, do
>   nothing".
> - `MySql::putTask()` returns an `InvalidArgumentException` **by value** for a
>   missing id, as designed, but the rejected-id test had to be split in two:
>   `0` / `''` / `null` are admitted by the type and refused by the runtime guard,
>   while `[]` and `stdClass` are refused by PHP itself. `true` and `1.5` were
>   dropped from the list — a non-`strict_types` caller coerces them to `1` and
>   `1` is a legal job id, so the type, not a runtime check, is what rejects them.
> - `Nsq::putTask()` routes `$readyWait > 0` down the `DPUB` path and `0` down
>   `PUB` (the old code took the `DPUB` path for an explicit `0`). Same bytes on
>   the wire, one branch fewer.
> - `DynamoSQS::putTask()` calls `getEstimatedTTL()` for every `$readyWait`
>   including `0`, so a negative value still reaches the DynamoDB TTL guard and
>   throws `InvalidArgumentException` as before.
> - `Worker\Serialized::dispatchOriginalMessage()` returns `bool` and logs the
>   failure with `get_class($result)` inline. A `$context` array would need the
>   `logError()` widening that belongs to plan 6, which is not implemented.
> - `DefaultQueueNameTest` asserts the recorded call is
>   `['putTask', …, ['readyWait' => 0]]`: `publish()` passes no extra argument, but
>   the double sees its own default. Filtering `0` out would have hidden an
>   explicit `readyWait: 0`, which is a real instruction.
> - The recording map in `TestAdapter` is keyed **alphabetically**
>   (`jobId`, `jobTtr`, `messageId`, `noSleep`, `priority`, `putAsDone`,
>   `readyWait`) because the phpcs `AlphabeticallySortedKeys` sniff enforces it. A
>   test that asserts that map with `assertSame` on the whole array fails on key
>   order alone, and `assertEqualsCanonicalizing` does **not** rescue it (it
>   canonicalizes only one side). Assert each option by name instead, plus an
>   `assertCount` to prove nothing extra was forwarded. Named arguments carry no
>   order, so this costs nothing in what the tests prove.
> - `AbstractPublisher::$adapter` is `?AbstractAdapter` and is null after
>   `__sleep()` strips it, so every method that uses it needs an explicit null
>   guard or Psalm reports `PossiblyNullReference`. Under the plan's error policy
>   that guard **returns** rather than throws: `start()`, `ready()`,
>   `hasWorkers()` and `finish()` return `false`, and `publish()` returns a
>   `RuntimeException` naming `__wakeup()`. A null adapter used to be a fatal
>   `Error`; it is now a reported failure, which is what the rest of the contract
>   does.

## The design

The contract stops describing a bag of options and starts describing the one thing every adapter
can do:

```php
abstract class AbstractAdapter
{
    /**
     * Put a job into the queue
     *
     * @param string|Stringable $body the job payload
     *
     * @return string|null|Throwable the job id, null when this adapter has no job ids,
     *                              the failure otherwise
     */
    abstract public function putTask(string|Stringable $body): null|string|Throwable;
}
```

Each adapter then **widens** it by appending its own optional parameters — legal in PHP, verified
in the `app-php83` container (a child may add trailing optional parameters). No adapter has to
accept, ignore or guess a parameter that belongs to another backend, and a bespoke adapter is free
to invent whatever signature its storage needs.

| Adapter | widened signature | result |
|---|---|---|
| `Beanstalk` | `putTask(string\|Stringable $body, int $readyWait = 0, ?int $jobTtr = null, ?int $priority = null): string\|Throwable` | always an id |
| `Redis` | `putTask(string\|Stringable $body, int $readyWait = 0): string\|Throwable` | always an id |
| `Nsq` | `putTask(string\|Stringable $body, int $readyWait = 0, ?int $jobTtr = null): null\|Throwable` | no ids |
| `DynamoSQS` | `putTask(string\|Stringable $body, int $readyWait = 0, int\|string\|null $messageId = null): null\|Throwable` | no ids |
| `MySql` | `putTask(string\|Stringable $body, int\|string\|null $jobId = null, bool $putAsDone = false, bool $noSleep = false): string\|Throwable` | the `$jobId` |

Narrowing the return union is covariance, so `Beanstalk` drops `null` and `Nsq` drops `string`
(verified). That is what replaces the old `bool|string|int` + `false` ambiguity: `true` used to
mean "stored, no id" and `false` "failed", and nothing could tell a deliberate no-id apart from a
failure at a glance.

### Error policy (one rule, two outcomes)

- **A bad argument throws** — it is a bug in the caller, not a transport failure. This preserves
  today's behaviour: `Nsq` still throws `RuntimeException` for a `$jobTtr` above `msg_timeout` or
  a `$readyWait` above `max_req_timeout`, `DynamoSQS` still throws `InvalidArgumentException` for
  a TTL below the DynamoDB limit. The existing `expectException` tests stay green.
- **A transport or storage failure returns the `Throwable`** instead of `false`. The caller logs
  it, and the exception object survives with its type, message and previous chain.
- An adapter never does both for one call.

## Signature rules verified on PHP 8.3 (do not re-derive, these shape the code)

| Rule | Result | Consequence |
|---|---|---|
| child appends an **optional** parameter | legal | every adapter signature above is legal |
| child appends a **required** parameter | **fatal** `must be compatible with` | `MySql` cannot declare `int\|string $jobId`; it defaults to `null` and fails at runtime |
| child **narrows** a parameter type | **fatal** | the old `array $params` can never linger in a child; one hard break, one release |
| child **adds** `false` to the return union | **fatal** | `false` can never come back to `putTask()` |
| child **narrows** the return union | legal | `string\|Throwable` and `null\|Throwable` are both valid |
| a raw `string` passed to a `Stringable` parameter | **TypeError** | the base type must be `string\|Stringable`, never bare `Stringable` |

The `string`-is-not-`Stringable` trap is the one that would have broken
`AbstractPublisher::publish()` on the first commit: `serialize()` returns a `string`, and PHP 8's
automatic `Stringable` only applies to classes that declare `__toString()`.

## Deleted, with no replacement shim

The point of the redesign is that PHP itself now reports a mismatched parameter, so no translation
layer is needed or wanted:

| Deleted | Replaced by |
|---|---|
| `AbstractAdapter::PARAM_JOBTTR`, `PARAM_READYWAIT` | the `$jobTtr` / `$readyWait` parameters |
| `Beanstalk::PARAM_PRIORITY` | the `$priority` parameter |
| `DynamoSQS::PARAM_MESSAGE_ID` | the `$messageId` parameter |
| `MySql\PutTaskParam` (backed enum, added in the unreleased 5.x) | the `$jobId` / `$putAsDone` / `$noSleep` parameters |
| `putTask(…, array $params = [])` | the widened signature |
| `MySql::jobIdParam()`, `MySql::hasParam()` | direct parameter reads |
| `false` as a put result | a returned `Throwable` |
| the dead `PARAM_JOBTTR` comment block in `Redis.php` (lines 516-523) | — |

`AbstractAdapter::JOBTTR_DEFAULT` **stays**: `Nsq::$config['msg_timeout']` is initialised from it
(`src/Adapter/Nsq.php:108`) and `tests/Adapter/NsqAdapterCoreTest.php:710` asserts on it. It
becomes the default of the `$jobTtr` parameter in `Beanstalk` and `Nsq`. `Beanstalk::PRIORITY_DEFAULT`
likewise becomes the default of `$priority`.

"Which parameters does this adapter accept?" is now answered by reading its signature, and a wrong
name is reported by the engine as `Error: Unknown named parameter $jobttr`. No reflection helper,
no hand-maintained list that can rot, nothing to cache.

## `publish()` and the delayed-message payload

`AbstractPublisher` is the only adapter-agnostic entry point, so it absorbs the widened arguments:

```php
public function publish(mixed $serializable, mixed ...$params): null|string|Throwable
{
    if (!$this->bind) {
        return new RuntimeException('Publisher is not started: call start() first');
    }

    return $this->adapter->putTask($this->serialize($serializable), ...$params);
}
```

Named arguments pass straight through, which is what makes an adapter-agnostic caller possible
again — the caller uses only the parameters every adapter has:

```php
$publisher->publish($message, readyWait: 5);            // works on all five adapters
$publisher->publish($message, readyWait: 5, jobTtr: 30); // Beanstalk, Nsq
$publisher->publish($message, jobId: 'a-1');             // MySql
```

A `Serialized` message has to *carry* those arguments through the queue, so
`Message\Serialized::$publishOptions` stays an array but changes meaning: it becomes a
**named-argument map** consumed with a splat, keyed by parameter name rather than by the old
constants.

```php
/** @var array<string, mixed> named arguments forwarded to AbstractPublisher::publish() */
protected array $publishOptions = [];

// src/Worker/Serialized.php
$result = $publisher->publish($message, ...$message->getPublishOptions());
```

A delayed job published before this change no longer replays — its payload holds the retired
`jobttr` / `readywait` keys and PHP raises `Error: Unknown named parameter`. That is accepted: 5.x
is unreleased and the queue is expected to be drained or purged across the upgrade. The `Error`
names the offending key, so the failure is self-explanatory in the worker log.

One diagnostic guard is worth keeping in `publish()` — it accepts nothing, it only turns a
confusing `TypeError` deep in the stack into a migration message:

```php
foreach ($params as $param) {
    if (is_array($param)) {
        throw new InvalidArgumentException(
            'publish() takes named arguments, not an options array: publish($message, readyWait: 5)'
        );
    }
}
```

## Signature per adapter, in detail

`Beanstalk` — the array lookups become typed reads and the three defaults move into the signature:

```php
#[Override]
public function putTask(
    string|Stringable $body,
    int $readyWait = 0,
    ?int $jobTtr = null,
    ?int $priority = null
): string|Throwable {
    if (!$this->connected) {
        return new RuntimeException('Beanstalk adapter is not connected');
    }
    try {
        $id = $this->client->put(
            $priority ?? self::PRIORITY_DEFAULT,
            $readyWait,
            $jobTtr ?? self::JOBTTR_DEFAULT,
            (string) $body
        );
    } catch (Throwable $e) {
        return $e;                       // transport failure becomes a value
    }

    return false === $id ? new RuntimeException('beanstalkd rejected the job') : (string) $id;
}
```

`Nsq` — the guard messages stop quoting a constant name, because there is no key any more:

```php
if (null !== $jobTtr && $jobTtr > $this->config['msg_timeout']) {
    throw new RuntimeException('Desired jobTtr ' . $jobTtr . ' > msg_timeout ' . $this->config['msg_timeout'] . ' …');
}
```

`MySql` — the mandatory `$jobId` cannot be required in the signature (verified: fatal), so it
stays nullable and fails with a value; a convenience method covers the common case:

```php
public function putTask(
    string|Stringable $body,
    int|string|null $jobId = null,
    bool $putAsDone = false,
    bool $noSleep = false
): string|Throwable {
    if (null === $jobId || '' === (string) $jobId) {
        $this->logError(__FUNCTION__ . ' Missing job id parameter');

        return new InvalidArgumentException('MySql::putTask() requires a job id, pass $jobId');
    }
    …
}

/** the mandatory-argument form, for callers that always have an id */
public function putTaskTo(int|string $jobId, string|Stringable $body, bool $putAsDone = false, bool $noSleep = false): string|Throwable
```

`$noSleep` and `$putAsDone` become real booleans instead of `isset($params['nosleep'])` key-presence
checks, so `'nosleep' => null` no longer silently means "sleep".

## Out of scope, deliberate

- `pickTask()` still returns `bool|array` and keeps its third metadata element. The same
  `null|array|Throwable` treatment is the natural follow-up, but it touches the worker generator
  and belongs in its own plan.
- `AbstractWorker` is unchanged apart from `Worker\Serialized` (below).
- The 12 `example/` files are updated because they are documentation, but they are not shipped.

---

## Phase A — red tests (must fail on current code)

| Test | File | Asserts |
|---|---|---|
| `testPutTaskReturnsThrowableInsteadOfFalse` | `tests/Adapter/BeanstalkAdapterTest.php`, `RedisAdapterCoreTest.php`, `NsqAdapterCoreTest.php`, `DynamoSQSAdapterTest.php` | a disconnected or rejected put returns a `Throwable`, not `false` |
| `testPutTaskReturnsNullWhenAdapterHasNoIds` | `tests/Adapter/NsqAdapterCoreTest.php`, `DynamoSQSAdapterTest.php` | a successful put yields `null` |
| `testPutTaskAcceptsWidenedArguments` | `tests/Adapter/BeanstalkAdapterTest.php` | `putTask('body', 2, 3, 1)` reaches the fake server as `put 1 2 3` |
| `testPutTaskRejectsRetiredKeyName` | `tests/Adapter/AbstractAdapterTest.php` | `putTask('body', jobttr: 5)` raises `Error: Unknown named parameter $jobttr` |
| `testInvalidArgumentStillThrows` | `tests/Adapter/NsqAdapterCoreTest.php`, `DynamoSQSAdapterTest.php` | out-of-range `$jobTtr` / `$readyWait` still **throws** (existing tests, unchanged) |
| `testMySqlPutTaskWithoutJobIdReturnsThrowable` | `tests/Adapter/MySqlAdapterTest.php` | the missing `$jobId` is a returned `InvalidArgumentException`, not `false` |
| `testPublishAcceptsNamedArguments` | `tests/Publisher/AbstractPublisherTest.php` | `publish($msg, readyWait: 5)` reaches `putTask()` |
| `testPublishRejectsOptionsArray` | `tests/Publisher/AbstractPublisherTest.php` | `publish($msg, ['readywait' => 5])` throws the migration `InvalidArgumentException` |
| `testPublishBeforeStartReturnsThrowable` | `tests/Publisher/AbstractPublisherTest.php` | not-started `publish()` returns a `RuntimeException` |
| `testWorkerReplaysNamedArguments` | `tests/Worker/SerializedWorkerTest.php` | `['jobTtr' => 9]` in the payload reaches `putTask()` as `jobTtr: 9` |
| `testWorkerTreatsReturnedThrowableAsFailure` | `tests/Worker/SerializedWorkerTest.php` | a republish returning a `Throwable` is logged and **not** acknowledged as processed |
| `testStringBodyIsAcceptedByStringableParameter` | `tests/Adapter/AbstractAdapterTest.php` | `putTask('a plain string')` does not raise a `TypeError` (the stringable trap) |

## Phase B — implementation

1. `src/Adapter/AbstractAdapter.php` — reduce `putTask()` to the one-parameter form; delete
   `PARAM_JOBTTR` / `PARAM_READYWAIT`; keep `JOBTTR_DEFAULT`.
2. `Beanstalk` — widened signature, `false` → `Throwable`, delete `PARAM_PRIORITY`.
3. `Redis` — widened signature, `false` → `Throwable`, delete the dead `PARAM_JOBTTR` comment block.
4. `Nsq` — widened signature, `false` → `Throwable`, reword the guard messages.
5. `DynamoSQS` — widened signature, `false` → `Throwable`, delete `PARAM_MESSAGE_ID`.
6. `MySql` — widened signature, add `putTaskTo()`, delete `jobIdParam()` / `hasParam()`, delete
   `src/Adapter/MySql/PutTaskParam.php` and its test.
7. `src/Publisher/AbstractPublisher.php` — variadic `publish()` returning `null|string|Throwable`,
   plus the options-array diagnostic guard.
8. `src/Message/Serialized.php` — `$publishOptions` becomes a named-argument map; update the
   docblock on the property, the constructor parameter and `getPublishOptions()`.
9. `src/Worker/Serialized.php` — `dispatchOriginalMessage()` must stop casting the result to
   `string` (a `Throwable` cannot be cast) and must splat the named arguments.
10. `tests/Support/TestAdapter.php` — base signature; `putTaskResult` can now hold a `Throwable`.
    Keep `ThrowingPutTaskAdapter` (programmer-error branch) and add `FailingPutTaskAdapter`
    returning a `Throwable` (transport branch).
11. The 12 `example/` files — `[PARAM_READYWAIT => n]` → `readyWait: n`, and every
    `$result = $publisher->publish(...)` truthiness check must test `!$result instanceof Throwable`,
    because a `Throwable` object is truthy.
12. `UPGRADING` — rewrite the 5.x entries: drop the `PutTaskParam` paragraph entirely (it never
    shipped) and add the new contract, the removed constants, `false` → `Throwable`, the
    array → named-argument migration, and the rule that a child may no longer declare required or
    narrowed parameters.
13. `AGENTS.md` — the `putTask()` contract is one parameter; adapters widen it.

`README.md` needs no change: it documents the publish path but never mentions `$params` or the
`PARAM_*` constants (verified by grep).

### Register: existing tests that change

| Test file | Line(s) | Current | After |
|---|---|---|---|
| `tests/Adapter/BeanstalkAdapterTest.php` | 51 | `assertSame('42', putTask('body'))` | unchanged |
| | 64-68 | 3-key options array | `putTask('body', 2, 3, 1)` |
| | 77, 173, 328 | `assertFalse(putTask('body'))` | `assertInstanceOf(Throwable::class, …)` |
| `tests/Adapter/RedisAdapterCoreTest.php` | 81, 365 | `assertFalse(…)` | `assertInstanceOf(Throwable::class, …)` |
| | 352 | `putTask('body', [PARAM_READYWAIT => 5])` | `putTask('body', 5)` |
| `tests/Adapter/NsqAdapterCoreTest.php` | 127 | `assertFalse(…)` | `assertInstanceOf(Throwable::class, …)` |
| | 375, `NsqAdapterTest.php` 45 | `assertTrue(…)` | `assertNull(…)` |
| | 138, 149, 364 | options array on a throwing call | `putTask('body', jobTtr: …)` — **still throws** |
| `tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php` | 37, 56, 88, 101 | `assertTrue(…)` | `assertNull(…)` |
| | 72, 79 | `assertFalse(…)` | `assertInstanceOf(Throwable::class, …)` |
| | 88, 101, 114 | `PARAM_READYWAIT` / `PARAM_MESSAGE_ID` | `$readyWait` / `$messageId`; line 114 **still throws** |
| `tests/Adapter/MySqlAdapterTest.php` | 166, 184-185, 205, 221-222, 234, 249, 261, 272 | `PutTaskParam::*->value` options arrays | positional / named arguments |
| `tests/Adapter/MySql/PutTaskParamTest.php` | whole file | asserts the enum | **deleted** |
| `tests/Adapter/AbstractAdapterTest.php` | 14-15 | asserts `PARAM_JOBTTR` / `PARAM_READYWAIT` | **deleted** (line 16, `JOBTTR_DEFAULT`, stays) |
| `tests/Support/TestAdapter.php` | 78 | `array $params = []` | base signature, records the widened args |
| `tests/Support/ThrowingPutTaskAdapter.php` | 15 | `array $params = []` | base signature; add a returning sibling |
| `tests/Publisher/AbstractPublisherTest.php` | 94, 103 | records `$params` | records the widened args |
| `tests/Worker/SerializedWorkerTest.php` | 37, 53 | `['jobttr' => 9]` in and out | `['jobTtr' => 9]` |
| `tests/Message/SerializedTest.php` | 18, 23 | `['jobttr' => 5]` | `['jobTtr' => 5]` |

### Verification (inside the `app-php83` container per AGENTS.md)

```bash
$script = @'
cd /app
php -l src/Adapter/AbstractAdapter.php
php -l src/Adapter/Beanstalk.php
php -l src/Adapter/Redis.php
php -l src/Adapter/Nsq.php
php -l src/Adapter/DynamoSQS.php
php -l src/Adapter/MySql.php
php -l src/Publisher/AbstractPublisher.php
php -l src/Message/Serialized.php
php -l src/Worker/Serialized.php
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src/Adapter/Redis.php src/Adapter/Nsq.php src/Adapter/MySql.php src/Publisher/AbstractPublisher.php src/Message/Serialized.php src/Worker/Serialized.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Adapter/AbstractAdapter.php src/Adapter/Beanstalk.php src/Adapter/Redis.php src/Adapter/Nsq.php src/Adapter/DynamoSQS.php src/Adapter/MySql.php
php ./vendor/bin/psalm.phar --config build/psalm.xml --no-diff --show-info=true src/Adapter/AbstractAdapter.php src/Publisher/AbstractPublisher.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter 'Adapter|Publisher|Serialized'
php ./vendor/bin/phpunit --configuration=phpunit.xml
'@
$script | docker exec -i app-php83 bash -s
```

Then the full sweep from `AGENTS.md` ("Code quality inside the container"): `phpcs src tests`,
`phpcbf` idempotency, project-wide `phpstan`, `psalm --stats`, the whole PHPUnit suite and
`composer app-code-quality`.

`example/` is excluded from both the phpcs ruleset (`<exclude-pattern>*/example/*</exclude-pattern>`)
and the PHPStan paths (`excludePaths: ../example/*`), so `php -l` is the **only** automated gate
there. The examples are the documentation for the new calling convention, so lint every one of them
explicitly — a missed call site there is invisible to every other tool in the suite.

`php build/check-classes.php` is the gate that matters most here: a child that narrows a parameter
or appends a required one is a **load-time fatal**, not a test failure, and it would otherwise kill
the PHPUnit run mid-way with no summary.

## Risks for the implementing agent

- **The one-line signature is the whole contract.** Any adapter that keeps a second `array $params`
  parameter is a load-time fatal. Change all five in the same commit or none.
- **`false` cannot come back** (verified). If a caller needs a boolean, map `null|string` → success
  and `Throwable` → failure at the edge; do not reintroduce `false` in an adapter "temporarily".
- **A `Throwable` is truthy.** All 12 example call sites and any user code doing
  `if ($publisher->publish($m))` treats a failure as success. This is the most likely way to ship a
  silent bug with this change — grep the examples and the README for truthiness checks.
- **`Worker\Serialized::dispatchOriginalMessage()` casts the result to `string`.** A `Throwable`
  cannot be cast; that line throws an `Error` instead of the republish working. It is a two-line
  fix that no test covers until the new failure-path test exists — write that test first.
- **Queues must be drained across the upgrade.** A `Serialized` payload holding `jobttr` /
  `readywait` now fails with `Error: Unknown named parameter`. This is deliberate, but say it in
  `UPGRADING` and in the release notes, or an operator will read it as a regression.
- **`JOBTTR_DEFAULT` is load-bearing for `Nsq`** (`msg_timeout`, asserted at
  `tests/Adapter/NsqAdapterCoreTest.php:710`). Do not move or rename it.
- **PHPStan findings depend on the file set.** A child that widens the signature is fine, but run
  PHPStan on each changed file *alone* as well as on `src`, or a narrowing mistake will only show
  up in one of the two runs.
- **Decide whether `publish()` without `start()` returns or throws.** It returns a `Throwable`
  (`RuntimeException('Publisher is not started')`) in this plan, because `publish()` already has a
  failure channel; note it in `UPGRADING`.
