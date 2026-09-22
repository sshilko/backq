# BackQ Code Coverage Completion Plan

- Status: draft — baseline measured 2026-09-22 in the dockerized `app.php81` image (PHP 8.1.34, Xdebug 3.5.3)
- Target: cover all uncovered `src/` files and close the deepest partial-coverage gaps; add a CI coverage gate
- Related: `plans/php-8.3-and-repository-review-improvement-plan.md` (Phase D), `plans/testing-aws-sns-dynamodb.md`

## 1. Current baseline

Measured with the suite run that already includes the Redis/NSQ adapter integration tests
(175 tests, 414 assertions):

```
Summary:
  Classes: 50.00% (17/34)
  Methods: 60.00% (114/190)
  Lines:   69.79% (982/1407)
```

Reproduce:

```
docker compose -f build/docker-compose.yaml up -d --wait redis nsq app.php81
docker compose -f build/docker-compose.yaml exec -T -e XDEBUG_MODE=coverage app.php81 \
  php ./vendor/bin/phpunit --configuration=phpunit.xml --coverage-text --colors=never
```

> phpunit.xml declares `<source>` for `src/` but does not set
> `includeUncoveredFiles`. PHPUnit 10 defaults it to **false**, so files with zero
> executed lines are silently **omitted** from the report. The 11 files in §2 therefore
> never appear in `--coverage-text` output today even though they hold library code.
> The summary counting "34 classes" is derived from the visible subset only.

## 2. Files with zero code coverage (omitted from the report)

| File | Purpose of the uncovered code | Exposure |
|---|---|---|
| `src/Adapter/IO/AbstractIO.php` | Abstract I/O contract (9 abstract methods) | None (abstract — enforced via children) |
| `src/Adapter/IO/Exception/IOException.php` | Exception marker | None (no logic) |
| `src/Adapter/IO/Exception/RuntimeException.php` | Exception marker | None (no logic) |
| `src/Adapter/IO/Exception/TimeoutException.php` | Exception marker | None (no logic) |
| `src/Publisher/Closure.php` | Abstract publisher, `$queueName = 'closure'`, `setupAdapter()` | Queue-name contract for the `"closure"` queue |
| `src/Publisher/Guzzle.php` | Abstract publisher, `$queueName`, `setupAdapter()` | Queue-name contract for the `"guzzle"` queue |
| `src/Publisher/Process.php` | Abstract publisher, `$queueName`, `setupAdapter()` | Queue-name contract for the `"process"` queue |
| `src/Publisher/Serialized.php` | Abstract publisher, `$queueName`, `setupAdapter()` | Queue-name contract — default is the literal placeholder `'mydynamodbtablenameandsqsqueuename'` (overridden by the example) |
| `src/Publisher/Amazon/SNS/Application/PlatformEndpoint/Publish.php` | Abstract publisher, `$queueName = 'aws_sns_endpoints_publish_'` | SNS publish queue naming |
| `src/Publisher/Amazon/SNS/Application/PlatformEndpoint/Register.php` | Abstract publisher, `$queueName = 'aws_sns_endpoints_register_'` | SNS register queue naming |
| `src/Publisher/Amazon/SNS/Application/PlatformEndpoint/Remove.php` | Abstract publisher, `$queueName = 'aws_sns_endpoints_remove_'` | SNS remove queue naming |
| `src/Worker/Amazon/SNS/SnsClient.php` | 3 thin `parent::` passthroughs (`publish`, `deleteEndpoint`, `createPlatformEndpoint`) | None (pure delegation) |
| `src/Worker/Amazon/SNS/Client/Exception/NetworkException.php` | Exception type (extends `RuntimeException`) | Used by SNS workers' `getPrevious()` type-check |

Priorities: the exception classes, `AbstractIO`, and `SnsClient` are logic-free — cheap to
cover as a side-effect (assert `instanceof`, call passthrough) but low value. The higher
value are the **publisher queue-name contracts**, which today are only asserted implicitly
for `AbstractPublisher` internals.

## 3. Files with partial coverage (present in the report, gaps ranked)

Ranked by line-gap × method-gap significance:

| Class | Methods | Lines | Notable uncovered behavior |
|---|---|---|---|
| `BackQ\Adapter\Redis` | 7.14% (1/14) | 61.83% (115/186) | Timeout clamp logic, delayed `putTask`, reserved-job release on disconnect, `afterWork*` job-id mismatch, `BLOCKFOR_EMULATE`, `connect()` |
| `BackQ\Worker\Guzzle` | 0.00% (0/1) | 40.48% (17/42) | Whole `run()` loop, `onError`/`onFailure`, legacy "FCM" log string |
| `BackQ\Worker\Amazon\SNS\...\Publish` | 0.00% (0/2) | 49.12% (28/57) | Retry loop (`$reprocessedTasks`), `onFailure`, the double-ack `finally` bug |
| `BackQ\Worker\Closure` | 0.00% (0/1) | 57.58% (19/33) | `RecoverableException` retry branch, `finally` ack, unserialize guard |
| `BackQ\Worker\Serialized` | 0.00% (0/2) | 68.75% (33/48) | `__PHP_Incomplete_Class` publisher guard, republish path |
| `BackQ\Worker\AProcess` | 0.00% (0/1) | 29.21% (26/89) | Whole `run()`, deadline/`--`-skip, `symfony/process` launch |
| `BackQ\Adapter\Beanstalk` | 23.08% (3/13) | 75.25% (76/101) | After-work ack/fail paths, buried-jobs handling, disconnect |
| `BackQ\Adapter\Nsq` | 41.67% (10/24) | 69.65% (140/201) | Heartbeat handling (returns `['','',[]]`), real frame parsing, reconnect |
| `BackQ\Adapter\IO\StreamIO` | 30.00% (3/10) | 69.75% (83/119) | No-op tests (`addToAssertionCount(1)`), write-EOF, `select*`, `isSocketReady` |
| `BackQ\Adapter\DynamoSQS` | 60.00% (9/15) | 79.31% (69/87) | Failure/release branches, invalid SQS body path |
| `BackQ\Worker\AbstractWorker` | 61.11% (11/18) | 75.53% (71/94) | Idle timeout, restart-threshold, signals, `debug()`/`logError()` |
| `BackQ\Adapter\Beanstalk\Client` | 55.56% (5/9) | 81.52% (75/92) | Protocol edge cases, error frames |
| `BackQ\Worker\Amazon\SNS\...\Register` | 50.00% (1/2) | 83.87% (52/62) | `onFailure` / retry branch (reversed `is_subclass_of` bug) |
| `BackQ\Worker\Amazon\SNS\...\Remove` | 50.00% (1/2) | 79.63% (43/54) | `onFailure` / retry branch (reversed `is_subclass_of` bug) |
| `BackQ\Message\Serialized` | 75.00% (3/4) | 87.50% (7/8) | `getPublisher()` null-guard |
| `BackQ\Publisher\AbstractPublisher` | 83.33% (10/12) | 90.00% (27/30) | `getInstance()` factory, `__sleep`/`__wakeup` adapter rebuild |

## 4. Implementation phases

### Phase 1 — visibility (tiny, do first)
1. Set `includeUncoveredFiles="true"` in `phpunit.xml` `<source>` so the §2 files appear
   in every future report (the numbers in §2–§3 were reconstructed by cross-checking the
   report against `src/`; the tooling should do it natively).
2. Add an `app-coverage` composer script running the §1 command.

### Phase 2 — bugs that coverage would have caught (write failing tests first)
Tie each to the repository-review plan's confirmed defects (documented in
`plans/php-8.3-and-repository-review-improvement-plan.md` §2.1):
1. SNS workers (Publish/Register/Remove): tests throwing the **BackQ** `SnsException` and a
   Guzzle `NetworkException` as `getPrevious()` — proves the reversed `is_subclass_of`
   branches and covers the retry/`onFailure` lines.
2. SNS `Publish` worker double-ack: a test asserting exactly one `afterWork*` send per
   iteration (fails today on the `finally` + `send(false)` path).
3. `Nsq` heartbeat frame: test that `pickTask()` does not return `['','',[]]` for `_heartbeat_`
   (covers the readFrame/pickTask path and the current bug).
4. `Redis` adapter: unit-test `setWorkTimeout` clamp, delayed `putTask`, reserved-job
   release on `disconnect`, `afterWork*` id-mismatch exception.
5. `StreamIO`: replace the two `addToAssertionCount(1)` no-ops with real assertions
   (`stream_set_timeout`, repeated-`close` idempotence), plus write-EOF and `select*`.

### Phase 3 — worker behavior (largest line-gap wins)
1. `Closure` worker: `RecoverableException` retry branch, successful and failed runs.
2. `Serialized` worker: valid payload republish; `__PHP_Incomplete_Class` publisher guard.
3. `Guzzle` worker: end-to-end `run()` with a live local HTTP stub (or `onError`-callback
   driven), assert success/failure acks.
4. `AProcess` worker: deadline-skip path, `symfony/process` success/failure with a stub
   Command, non-zero exit → `afterWorkFailed`.
5. `AbstractWorker`: idle-timeout, restart threshold, signal handling (via `pcntl`-guarded
   branch test or reflection), `debug()`/`logError()`.

### Phase 4 — publisher queue-name contracts
1. Subclass each `src/Publisher/*` abstract in tests (like `tests/Support/TestPublisher`),
   assert `getQueueName()` defaults (`closure`, `guzzle`, `process`,
   `aws_sns_endpoints_publish_/register_/remove_`), and pin Serialized's placeholder
   `'mydynamodbtablenameandsqsqueuename'` (or fix+document it alongside the example
   queue split in the repo-review plan).
2. SNS publishers: exercise `AbstractPublisher::publish()` round-trip with the
   `TestAdapter` for each of Publish/Register/Remove (closes the §2 zero-coverage entries
   with meaningful assertions).

### Phase 5 — CI gate
1. Add a GitHub Actions job: `composer app-coverage`, upload a Clover/XML report, enforce a
   line-coverage floor (start at the current 70%, raise per Phase 3-4 lands), and flag any
   file dropping to 0%.
2. Keep `--coverage-text`/Clover on the 8.3 image once the
   `Dockerfile.php83` upgrade (`plans/php-8.3-*` Phase B) lands.

## 5. Acceptance criteria

- `includeUncoveredFiles=true` report lists every `src/` class; no file at 0.00%.
- Line coverage ≥ 90%; BackQ\Adapter\Redis methods ≥ 80% (biggest method gap).
- SNS worker tests cover the `is_subclass_of`/NetworkException and double-ack paths.
- Redis/NSQ/StreamIO no-op tests replaced by asserting tests.
- `app-coverage` runs green in CI on every PR.