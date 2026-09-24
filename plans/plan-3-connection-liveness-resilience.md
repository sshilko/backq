# Plan 3 — Connection liveness & worker resilience (findings 3.1 – 3.6)

> Status: **documented only** — no code changes made. Intended for implementation by another agent.
> Scope: section 3 of the analysis report (liveness / worker resilience).
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-4-minor-notes-behavior.md`.

## How to execute this plan

Two steps per item: **Phase A** (failing tests, RED on current code) then **Phase B** (fix → GREEN).
Items 3.3 and 3.4 share one root decision — `AbstractWorker` must treat `pickTask() === false` as
*idle*, never as a fatal error — and they must be landed together with the adapter-side changes that
make real failures *throw* (otherwise broken adapters silently busy-loop). See the dependency note at
the end.

---

## 3.1 HIGH — Nsq::ping() is inverted

**Location:** `src/Adapter/Nsq.php`, `ping()` (lines ~177–185):

```php
if ($this->connected && $this->_io) {
    return $this->_io->isSocketReady();
}
return false;
```

**Mechanism:** `StreamIO::isSocketReady()` returns `true` when the socket is **broken** (EOF /
timed out — verified by `StreamIOTest::testIsSocketReadyReturnsTrueAfterPeerClose` and
`testIsSocketReadyReturnsFalseWhenHealthy`). `ping()` therefore returns `true` for a dead connection
and `false` for a healthy one — exactly backwards for consumers like
`AbstractPublisher::ready()` / `AbstractAdapter::ping()` contract ("returns TRUE if connection is
alive").

**Phase A — failing tests** (`tests/Adapter/NsqAdapterCoreTest.php`):

Flip the existing test `testPingReportsSocketHealthWhenConnected` (lines ~245–258): a healthy connected
`ping()` must be `true`:

```php
$this->assertTrue($nsq->connect());
$this->assertTrue($nsq->ping());
```

Why RED today: the current code asserts `assertFalse($nsq->ping())` — the buggy value. After flipping,
the healthy `ping()` returns `false` → assertion fails. Rename the test to
`testPingReportsTrueWhenConnectedAndHealthy`.

Add the dead-socket case. The `idle` fake-server mode holds the connection for 3 s then closes
(`usleep(3000000)` before `fclose`), so:

```php
public function testPingReportsFalseWhenSocketDead(): void
{
    [$process, $pipes, $port] = $this->startFakeServer('idle');
    $nsq = new Nsq(self::TEST_HOST, $port);
    $nsq->setTriggerErrorOnError(false);

    try {
        $this->assertTrue($nsq->connect());
        // idle mode closes the connection after 3s
        usleep(3200000);

        $this->assertFalse($nsq->ping());
    } finally {
        $nsq->disconnect();
        $this->stopFakeServer($process, $pipes);
    }
}
```

Why RED today: after the peer closes, `isSocketReady()` returns `true` → `ping()` returns `true` →
`assertFalse` fails. (~3 s test; acceptable, comparable to `testIdleTimeoutBreaksLoop`.)

**Phase B — fix** (`src/Adapter/Nsq.php`, `ping()`):

```php
public function ping(bool $reconnect = true): bool
{
    if ($this->connected && $this->_io) {
        /**
         * isSocketReady() returns TRUE when the socket is broken (EOF/timed out)
         */
        return !$this->_io->isSocketReady();
    }

    return false;
}
```

Guard edge: if `_io` exists but `isSocketReady()` throws `RuntimeException('No active socket
connection')` (closed socket), consider wrapping in try/catch → `return false`. Keep
`testPingReturnsFalseWhenNotConnected` green.

---

## 3.2 MEDIUM — Redis::ping() lets RedisException escape

**Location:** `src/Adapter/Redis.php`, `ping()` (lines ~260–279). `$redis->ping()` on a dead phpredis
connection throws `\RedisException` (or `RedisException extends RuntimeException`); it is not caught,
so `ping()` propagates instead of returning `false`.

**Phase A — failing test** (`tests/Adapter/RedisAdapterCoreTest.php`; reuses the existing `wireManager`
helper and `createMock(\Redis::class)` pattern from `testPingReportsSuccessWhenQueueIsAlive`):

```php
public function testPingReturnsFalseWhenRedisThrows(): void
{
    $redis = new Redis();
    $this->setState($redis, true, ConnectionState::BindRead);
    $redis->setTriggerErrorOnError(false);

    $redisClient = $this->createMock(\Redis::class);
    $redisClient->method('ping')->willThrowException(new \RedisException('Lost connection'));

    $queue = $this->createMock(Queue::class);
    $queue->method('getRedis')->willReturn($redisClient);
    $this->wireManager($redis, $queue);

    $this->assertFalse($redis->ping());
}
```

Why RED today: `$redis->ping()` throws `RedisException('Lost connection')` — the test fails with an
unexpected exception instead of `assertFalse`.

**Phase B — fix** (`src/Adapter/Redis.php`, `ping()`): catch and report liveness errors:

```php
public function ping(bool $reconnect = true): bool
{
    if ($this->connected) {
        try {
            $redisQueue = $this->queue->getConnection(self::CONNECTION_NAME);
            assert($redisQueue instanceof Queue);
            $redis = $redisQueue->getRedis();
            assert($redis instanceof \Redis);
            $pong = $redis->ping();

            if (in_array($pong, [true, '+PONG'], true)) {
                $this->logDebug(__FUNCTION__ . ' successful');

                return true;
            }
        } catch (Throwable $ex) {
            $this->logError(self::class . ' ' . __FUNCTION__ . ' exception: ' . $ex->getMessage());
        }
    }
    $this->logDebug(__FUNCTION__ . ' failed');

    return false;
}
```

`Throwable` import already exists in the file. Keep `testPingReportsSuccessWhenQueueIsAlive` green.

---

## 3.3 HIGH — workers die on blips; Beanstalk busy-loops with log spam

**Two cooperating defects:**

1. **Beanstalk swallows connection failures** (`src/Adapter/Beanstalk.php`, `pickTask()` lines
   ~215–233; `src/Adapter/Beanstalk/Client.php`, `reserve()` lines ~113–200): a broken connection makes
   `Client::reserve()` land in the `default:` case (status `''` from a `false` `_read()`) and return
   `false`; `Beanstalk::pickTask()` catches everything, `logError`s (which `trigger_error`s an
   `E_USER_WARNING`), and returns `false`. A worker with a set work-timeout then busy-loops, logging a
   warning per iteration.
2. **`AbstractWorker` treats `false` as a fatal** (`src/Worker/AbstractWorker.php`, `work()` lines
   ~313–326): `if (!$timeout) { throw new Exception('Worker failed to fetch new job'); }` — but
   `false` is also the documented *idle* signal used by Nsq heartbeats (Nsq::pickTask returns `false`
   after answering a heartbeat) and Redis/SQS idle polls. NSQ and Redis workers configured without an
   explicit work-timeout therefore crash on the first idle cycle (see 3.4).

**Phase A — failing tests:**

a) `tests/Adapter/BeanstalkAdapterTest.php` — connection failure must propagate instead of returning
`false`:

```php
public function testPickTaskPropagatesClientFailure(): void
{
    [$adapter, $client] = $this->adapterWithConnectedClient();
    $client->expects($this->once())
        ->method('reserve')
        ->willThrowException(new RuntimeException('connection lost'));

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('connection lost');

    $adapter->pickTask();
}
```

Why RED today: `pickTask()` catches the exception, logs it, returns `false`; no exception is raised →
`expectException` fails.

b) `tests/Worker/AbstractWorkerTest.php` — flip `testWorkThrowsWhenNoJobAndNoTimeout` (lines ~106–116)
into `testWorkIdlesWhenNoJobAndNoTimeout`. Use an idle-timeout so the loop terminates (the idle branch
does not count toward `restartThreshold`):

```php
public function testWorkIdlesWhenNoJobAndNoTimeout(): void
{
    $adapter                = new SleepingPickAdapter();
    $adapter->pickTaskResult = false;
    $logger                 = new RecordingLogger();
    $worker                 = new TestWorker($adapter);
    $worker->setLogger($logger);
    $worker->setTriggerErrorOnError(false);
    $worker->setWorkTimeout(null); // NSQ-style: no explicit work timeout
    $worker->setIdleTimeout(1);    // terminate after ~1s of idling

    $worker->run(); // must NOT throw 'Worker failed to fetch new job'

    $wholeLog = implode("\n", array_column($logger->records, 1));
    $this->assertStringNotContainsString('Worker failed to fetch new job', $wholeLog);
    $this->assertContains('disconnect', $adapter->calls);
}
```

Why RED today: `run()` throws `Throwable('Worker failed to fetch new job')` immediately. (~1 s runtime.)

**Phase B — fix:**

- `src/Adapter/Beanstalk/Client.php` `reserve()`: keep `TIMED_OUT → false` (idle) and
  `DEADLINE_SOON → false`, but make a broken connection throw instead of returning `false`:

```php
switch ($status) {
    case 'RESERVED':
        return [
            'id'   => $jobid,
            'body' => $this->_read($bodyN),
        ];
    case 'TIMED_OUT':
        if (!isset($timeout)) {
            $this->_error(__FUNCTION__ . " status = '" . $status . "', timeout=" . $streamTimeout);
        }

        return false;
    case 'DEADLINE_SOON':
        $this->_error(__FUNCTION__ . " status = '" . $status . "', timeout=" . $streamTimeout);

        return false;
    default:
        throw new RuntimeException(
            __FUNCTION__ . " connection failure, status = '" . $status . "', timeout=" . $streamTimeout
        );
}
```

- `src/Adapter/Beanstalk.php` `pickTask()`: rethrow failures after logging:

```php
try {
    $result = $this->client->reserve($timeout ?? $this->workTimeout);
    if (is_array($result)) {
        return [$result['id'], $result['body'], []];
    }
} catch (Throwable $e) {
    $this->logError(self::class . ' adapter ' . __FUNCTION__ . ' exception: ' . $e->getMessage());

    throw $e;
}

return false;
```

`$timeout ?? $this->workTimeout` also lands Plan 4 item 4.2 (timeout argument forwarding).

- `src/Worker/AbstractWorker.php` `work()`: remove the false-without-timeout throw; `false` is idle:

```php
} else {
    /**
     * No job was available this cycle (heartbeat / idle poll).
     * Not an error: adapters surface real connection failures by throwing.
     */
    yield null;
    yield null;
}
```

- Also make **DynamoSQS** play by the same rule: `src/Adapter/DynamoSQS.php` `pickTask()` (lines
  ~160–220) catches `AwsException` and returns `false`, which would now spin silently instead of
  crashing. Rethrow it:

```php
} catch (AwsException $e) {
    $this->logError($e->getMessage());

    throw $e;
}
```

  and flip `testPickTaskReturnsFalseOnAwsException` (lines ~186–194) to expect the exception — RED in
  Phase A, GREEN in Phase B. (`Redis::pickTask()` and `Nsq::pickTask()` already throw on real
  failures; heartbeat/idle return `false` and are now handled as idle.)

Keep green: `testPickTaskReturnsFalseWhenNoJob` (reserve `TIMED_OUT` → false), `testReserveWithTimeoutReturnsFalseOnTimeout`,
`testReserveOnDeadlineSoonReturnsFalse` (Beanstalk ClientTest), `testEmptyPayloadIsSkipped`
(Guzzle work-timeout set), `testIdleTimeoutBreaksLoop`, `testIdleTimeoutContinuesWhenOnIdleTimeoutFalse`.

---

## 3.4 MEDIUM — NSQ worker without setWorkTimeout() dies on the first heartbeat

Same root cause as 3.3(b): `Nsq::pickTask()` returns `false` after a heartbeat (writing `NOP`), and
`AbstractWorker` with `workTimeout === null` throws `Worker failed to fetch new job`. Any custom NSQ
worker that does not call `setWorkTimeout()` crashes on the first heartbeat.

**Phase A — failing test** (`tests/Worker/AbstractWorkerTest.php`) — explicit NSQ-flavor case:

```php
public function testHeartbeatStyleIdleWithoutTimeoutDoesNotKillWorker(): void
{
    $adapter                = new TestAdapter(); // pickTaskResult=false, never throws (NSQ heartbeat)
    $logger                 = new RecordingLogger();
    $worker                 = new ConfigurableWorker($adapter);
    $worker->setLogger($logger);
    $worker->setTriggerErrorOnError(false);
    $worker->setWorkTimeout(null);
    $worker->setIdleTimeout(1);

    $worker->run(); // must NOT throw

    $wholeLog = implode("\n", array_column($logger->records, 1));
    $this->assertStringNotContainsString('Worker failed to fetch new job', $wholeLog);
}
```

Why RED today: throws `Worker failed to fetch new job` on the first `false` pick.

**Phase B — fix:** the `AbstractWorker::work()` change in 3.3 (false = idle) is the fix. No separate
adapter change is needed — verify with `NsqAdapterCoreTest::testConnect...` + a real heartbeat in the
dockerized integration suite (`NsqAdapterTest`) if services are up. Document the new semantics (false =
idle; adapters throw on failures) in `UPGRADING`/AGENTS if appropriate.

---

## 3.5 MEDIUM — NSQ REQ with 0 ms delay → poison-job hot loop

**Location:** `src/Adapter/Nsq.php`, `afterWorkFailed()` (lines ~191–201):

```php
$this->writeCommand(sprintf(self::PROTO_REQUEUE, $workId, 0));
```

**Mechanism:** `REQ <id> 0` re-queues the message for immediate redelivery. A job that always fails is
therefore redelivered in a tight hot loop. The Belly/NSQ spec REQ delay is in **milliseconds**; a small
positive delay (e.g. 1000 ms) breaks the loop while keeping failed jobs retryable.

**Phase A — failing test** (`tests/Adapter/NsqAdapterCoreTest.php`) — mock `_io` and assert the wire
command carries a positive delay:

```php
public function testAfterWorkFailedRequeuesWithPositiveDelay(): void
{
    $io = $this->createMock(BackQ\Adapter\IO\StreamIO::class);
    $io->expects($this->once())
       ->method('write')
       ->with($this->callback(static function (string $data): bool {
           return 1 === preg_match('#^REQ msgid01234567890 [1-9][0-9]*\n$#', $data);
       }));

    $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
    $this->setState($nsq, true, ConnectionState::BindRead);
    (new ReflectionProperty(Nsq::class, '_io'))->setValue($nsq, $io);

    $this->assertTrue($nsq->afterWorkFailed('msgid01234567890'));
}
```

Why RED today: `writeCommand` sends `REQ msgid01234567890 0\n` (delay `0` does not match `[1-9]`) →
callback returns `false` → expectation fails. (NsqAdapterCoreTest needs `use BackQ\Adapter\IO\StreamIO;`
and `use function preg_match;` added.)

**Phase B — fix** (`src/Adapter/Nsq.php`):

```php
protected final const int REQUEUE_DELAY_MS = 1000;
```

```php
$this->writeCommand(sprintf(self::PROTO_REQUEUE, $workId, self::REQUEUE_DELAY_MS));
```

Keep green: `testAfterWorkFailedRequeuesMessage` (the `requeue` fake-server mode only checks the
command starts with `REQ`). Document the fixed 1 s requeue delay in `UPGRADING`.

---

## 3.6 MEDIUM — DynamoSQS afterWorkSuccess returns true even when SQS delete fails

**Location:** `src/Adapter/DynamoSQS.php`, `afterWorkSuccess()` (lines ~274–288):

```php
} catch (AwsException $e) {
    $this->logError($e->getMessage());
}
return true;
```

**Mechanism:** the `return true` is outside the `try`; on a failed `DeleteMessage` the worker
(node: `AbstractWorker::work()`) is told the ack succeeded, so the job is considered done even though
it will be redelivered after the visibility timeout → duplicate processing.

**Phase A — failing test** (`tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php`): flip
`testAfterWorkSuccessLogsOnAwsException` (lines ~247–255):

```php
public function testAfterWorkSuccessReturnsFalseOnAwsException(): void
{
    $exception = new AwsException('queue gone', new Command('DeleteMessage'));
    $sqs       = new MockHandler([$exception]);
    $adapter   = $this->makeAdapter(new MockHandler([]), $sqs);
    $adapter->setTriggerErrorOnError(false);

    $this->assertFalse($adapter->afterWorkSuccess('rh-9'));
}
```

Why RED today: `afterWorkSuccess` returns `true` on the exception path → `assertFalse` fails.

**Phase B — fix** (`DynamoSQS::afterWorkSuccess()`):

```php
public function afterWorkSuccess(int|string|null $workId): bool
{
    if ($this->sqsClient) {
        try {
            $this->sqsClient->deleteMessage(['QueueUrl' => $this->sqsQueueURL, 'ReceiptHandle' => $workId]);

            return true;
        } catch (AwsException $e) {
            $this->logError($e->getMessage());

            return false;
        }
    }

    return true; // no client attached: idempotent no-op, keep documented behavior
}
```

Keep green: `testAfterWorkSuccessDeletesByReceiptHandle`, `testAfterWorkSuccessOnMissingClientIsIdempotent`.
(Design note: with `afterWorkSuccess` now reporting `false`, `AbstractWorker::work()` throws
`Worker failed to acknowledge job result` — the worker exits instead of silently losing the message.
That is the intended resilience behavior.)

---

## Dependency map (land these together)

| Change | Needs |
|---|---|
| 3.3(b) worker false=idle | 3.3(a) Beanstalk rethrow **and** DynamoSQS rethrow (else silent busy-loops) |
| 3.3(a) Beanstalk rethrow | Plan 1 item 1.2 (truncated-body throw) integrates cleanly |
| 3.4 | 3.3(b) worker change only |
| 3.5, 3.6, 3.1, 3.2 | independent |

## Register: existing tests that assert buggy behavior (flip in Phase A / land with Phase B)

| Test file | Test | Current assertion | After fix |
|---|---|---|---|
| `tests/Adapter/NsqAdapterCoreTest.php` | `testPingReportsSocketHealthWhenConnected` | `assertFalse($nsq->ping())` | `assertTrue($nsq->ping())` |
| `tests/Adapter/RedisAdapterCoreTest.php` | (new) `testPingReturnsFalseWhenRedisThrows` | — | — |
| `tests/Adapter/BeanstalkAdapterTest.php` | (new) `testPickTaskPropagatesClientFailure` | — | — |
| `tests/Worker/AbstractWorkerTest.php` | `testWorkThrowsWhenNoJobAndNoTimeout` | expects `Worker failed to fetch new job` | idle, no exception |
| `tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php` | `testPickTaskReturnsFalseOnAwsException` | `assertFalse($adapter->pickTask())` | expects exception |
| `tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php` | `testAfterWorkSuccessLogsOnAwsException` | `assertTrue($adapter->afterWorkSuccess('rh-9'))` | `assertFalse(...)` |

## Verification (run inside the `backq.php83` container per AGENTS.md)

```bash
$script = @'
cd /app
php -l src/Adapter/Nsq.php
php -l src/Adapter/Redis.php
php -l src/Adapter/Beanstalk.php
php -l src/Adapter/Beanstalk/Client.php
php -l src/Adapter/DynamoSQS.php
php -l src/Worker/AbstractWorker.php
php -l tests/Adapter/NsqAdapterCoreTest.php
php -l tests/Adapter/RedisAdapterCoreTest.php
php -l tests/Adapter/BeanstalkAdapterTest.php
php -l tests/Adapter/Beanstalk/ClientTest.php
php -l tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php
php -l tests/Worker/AbstractWorkerTest.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s tests/Adapter/NsqAdapterCoreTest.php tests/Adapter/RedisAdapterCoreTest.php tests/Adapter/BeanstalkAdapterTest.php tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php tests/Worker/AbstractWorkerTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Adapter/Nsq.php src/Adapter/Redis.php src/Adapter/Beanstalk.php src/Adapter/Beanstalk/Client.php src/Adapter/DynamoSQS.php src/Worker/AbstractWorker.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter 'NsqAdapter|RedisAdapter|Beanstalk|DynamoSQS|AbstractWorker|GuzzleWorker'
php ./vendor/bin/phpunit --configuration=phpunit.xml
'@
$script | docker exec -i backq.php83 bash -s
```

Notes: `Beanstalk.php`, `Beanstalk/Client.php` and `DynamoSQS.php` carry class-level `@phpcs:disable` —
phpcs skips them; verify with `php -l` + PHPStan + tests.

## Risks for the implementing agent

- The worker false=idle change is behavioral: a *broken* Beanstalk/DynamoSQS connection now surfaces
  as a worker exception (supervisor restart) instead of silent false + deleted jobs. This is the
  intended direction (fail loud, restart fast) — confirm with the repo owner if that contradicts
  operational expectations, and document in `UPGRADING`.
- `testWorkIdlesWhenNoJobAndNoTimeout` / `testHeartbeatStyleIdleWithoutTimeoutDoNotKillWorker` run
  ~1 s each; keep `setIdleTimeout(1)` to bound them.
- Verify the full dockerized integration suite (`NsqAdapterTest` requiring a live nsqd) after 3.4:
  heartbeats at `heartbeat_interval_ms` are answered with `NOP` and the worker idles instead of dying.