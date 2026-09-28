# Plan 9 — `Redis::hasWorkers()` from a Redis-side worker heartbeat

> Status: **implemented.** Landed as described below; the corrections implementation
> forced are marked inline, and they are all in the *how*, none in the *what* — the registry is a
> zset of leases exactly as designed. Read the "Corrections this plan needed" section at the end
> before porting any of it.
> Scope: `Redis::hasWorkers()` (`src/Adapter/Redis.php:374`), the three methods that publish and
> drop a heartbeat (`:255` `disconnect()`, `:388` `pickTask()`, `:589` `bind()`), one new
> optional field on `RedisConfig`, the two Redis test files, the `README.md` feature table and the
> `UPGRADING` bullet that currently calls the method a stub.
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`, `plan-4-minor-notes-behavior.md`,
> `plan-5-put-task-contract.md`, `plan-6-error-log-deprecation.md`,
> `plan-7-adapter-architecture.md`.
>
> **The alternative to this plan is `plan-8-redis-has-workers-local-registry.md`**, which answers
> the same question from a process-local registry. **The two are alternatives, not steps.** Take
> this one when the publisher is a web request and the workers are on other hosts — the topology
> the library is actually used in, and the one a local registry cannot serve. Take Plan 8 when the
> publisher and the worker share a process, or when a write on the consumer's hot path is not
> acceptable. Deciding between them is the whole decision; everything below is the cost of the
> Redis-side answer.

## The problem

`Redis::hasWorkers()` is a stub that answers `false` and logs "not supported"
(`src/Adapter/Redis.php:367-379`). `Beanstalk` answers it for real from the server
(`statsTube` → `current-watching`, `Beanstalk.php:111-143`). The gap is not cosmetic: on Redis the
one caller in the library, `AbstractPublisher::hasWorkers()`
(`src/Publisher/AbstractPublisher.php:117-120`), hands the user a permanently-false answer on the
backend that most deployments use.

Plan 8 fixes the lie with a process-local registry and is 90 lines. It cannot answer the question
in the topology that matters:

```
  web request ──▶ Publisher ──▶ Redis ◀── worker process A   (plan 8: hasWorkers() = false)
                       │
                       └──▶ hasWorkers() is asked *here*, and A is not in this process
```

So the honest version of the feature needs the answer to live where both sides can see it: **in
Redis**. A worker announces itself to a per-queue registry with a lease; a publisher reads the
registry and prunes the expired leases. This is the same mechanism Laravel Horizon uses
(`WorkerRepository`, a Redis-backed per-supervisor record), and it is what `beanstalkd` does
natively with `current-watching`.

## The design

**One sorted set per queue.** `backq:workers:{queue}`, member = a token unique to the adapter
instance, score = the unix second at which the lease expires. Every verb below is a plain Redis
command, no Lua:

| Who | Command | Purpose |
|---|---|---|
| worker, on `bindRead()` | `ZADD key <serverNow + ttl> token` | announce |
| worker, on `pickTask()`, at most every `ttl / 3` s | `ZADD key <serverNow + ttl> token` | renew |
| worker, on `disconnect()` | `ZREM key token` | release early |
| publisher, on `hasWorkers()` | `TIME`, `ZREMRANGEBYSCORE key -inf <serverNow>`, `ZCARD key` | prune and count |

**The server's clock, on both sides.** The lease is written as an absolute expiry and compared
against *the server's* `TIME`, never against PHP's `time()`. A publisher in one container and a
worker in another routinely disagree by seconds, and the direction of the disagreement matters:

- worker clock **ahead** → the lease outlives its TTL by the skew. That is a **false positive**: a
  dead worker is reported as present for `skew + ttl` seconds. The only way a user gets hurt by
  this plan is exactly here.
- worker clock **behind** → a live worker is reported as absent. A false negative, which on a
  publish path means "publish anyway". Harmless.

Using `TIME` on both sides removes the failure entirely. It costs one extra round trip on the read
side, where `hasWorkers()` is not in any hot path, and on the write side it is amortised to one
command per `ttl / 3` seconds. Measured: `time()` through the same `Manager` object the adapter
already uses works, and **returns two strings** — `["1790613830","887094"]` — so the cast is
mandatory and the unit must be seconds, not milliseconds.

**A Lua variant, deliberately not taken.** `TIME` + `ZADD` can be one `EVAL`, and prune + count can
be one `EVAL`, at the cost of two caveats: `redis.call('TIME')` before a write makes the script
non-deterministic (fine on Redis ≥ 5 with effect replication, which `build/docker-compose.yaml:67`
provides via `redis:7-alpine`, but not with `replicate_commands()` on an old server), and
**whether phpredis applies `OPT_PREFIX` to `EVAL` key arguments is a phpredis-version-dependent
behaviour** — measured as *yes* on the pinned 6.3.0 (an `EVAL` writing `KEYS[1]` landed as
`backqprobe:backq:workers:…`), and a server that later disagrees would make `hasWorkers()` read an
unprefixed key and answer `false` forever, silently. Plain commands have neither caveat. Revisit
only if a measured round-trip cost ever matters, and pin the prefix behaviour in a test first.

**The registry is never load-bearing.** A worker that cannot write the registry must still work:
the announce, the renew and the release are each wrapped in `attempt()` and the answer is
discarded. A Redis ACL that permits the queue commands and not `ZADD` must not break every worker
in a deployment. This is a hard rule with a test behind it (9.6).

### What the answer means, stated precisely

`hasWorkers()` answers **"a worker was seen on this queue within the last `workerTtl` seconds"**.
It does **not** answer "a worker is idle right now", and the difference is not academic:

- a worker that is running a job longer than `workerTtl` is not reported. This is a false negative
  (publish anyway), and the only fix is `workerTtl > longest job`. Say this in the README, or the
  first user with a 10-minute job will file it as a bug.
- a worker that started 2 seconds ago is not reported until its first renew. Also a false negative.
  Plan 8 has no such window, which is the one place it is strictly better.
- a worker blocked in `blpop` **is** reported, which is the case that matters: it is waiting for
  exactly the job being published. The renew happens *before* the pop, so the block window does not
  expire the lease — see 9.3 for why that is bounded.

The bias is the same as Plan 8's: the only way to answer `true` is to find a lease that has not
expired, and every other outcome is `false`.

## Measured, not assumed

Every load-bearing claim was checked in `app-php83` (PHP 8.3.33, phpredis 6.3.0, Redis 7,
`zend.assertions=-1` so `assert()` in `src/` is compiled out). All of it goes through
`$this->queue()->getConnection(self::CONNECTION_NAME)->getRedis()`, which is the object
`pingServer()` (`:610-628`) already types as `\Redis` and calls `ping()` on:

| Question | Answer | How |
|---|---|---|
| `zadd` / `zcard` / `zremrangebyscore` through the `Manager`? | yes, all return `int` | probe on a prefixed connection |
| `zremrangebyscore $key '-inf' $now` | prunes correctly, returns the number removed | 3 members in, 1 stale out, `zcard` 2 |
| `time()` through the `Manager`? | yes — **as two strings** | `["1790613830","887094"]` |
| Does phpredis prefix a plain `zadd` key? | yes | raw client sees `backqprobe:backq:workers:<queue>` |
| Does phpredis prefix `EVAL` key args? | yes on 6.3.0 — version-dependent, so unused | raw client after an `EVAL` on `KEYS[1]` |
| Is `getRedis()` a `\Redis` at runtime? | **no** — it is `BackQ\Adapter\Redis\Manager` | probe; `assert()` is compiled out, and `Manager::__call` forwards |

The last row is why the sketches below copy `pingServer()`'s shape exactly — the `assert()` is what
psalm and phpstan read, and the `__call` chain is what actually runs. Do not "clean up" the assert
into a real type check, and do not add a second, different style of narrowing next to it.

## Out of scope (considered and deliberately excluded)

- **`CLIENT LIST`.** It would answer *remotely* with no writes at all — the same answer Plan 9 buys
  — but it needs a permission managed Redis often withholds, it is `O(clients)` on a shared server,
  it cannot see a worker that polls with `blockFor = null` (a supported configuration,
  `Redis.php:207-250`), and it cannot tell busy from idle. It is a monitoring tool, not an
  availability check.
- **A tri-state answer.** `bool` is the contract for 5.x (`UPGRADING:58-63`). "unknown" would need a
  new signature on two interfaces, three adapters and every third-party adapter.
- **Reading the queue's own keys.** `LLEN queues:{q}` is a backlog, not a worker; `ZCARD
  queues:{q}:reserved` is a job in flight, not a worker. Both answer the wrong question and both
  would produce a `true` on a queue nobody is consuming.
- **Heartbeats from `AbstractWorker`.** The adapter already sees every pick and every release, and
  the adapter is what a user may hand-replace. Keeping the feature in the adapter means a custom
  adapter does not silently lose it.
- **A `workerCount()` API.** `bool` is the return type; a count is thrown away.
- **Cleanup on `RedisQueue::clear()`.** illuminate's `clear()` touches `queues:{q}`,
  `:delayed`, `:reserved` and `:notify` (`vendor/illuminate/queue/RedisQueue.php:328-339`) and knows
  nothing about `backq:workers:{q}`. A registry can outlive a `clear()`. It is harmless (a lease
  expires) and out of scope — but it is a real operational detail, so it belongs in the README
  footnote rather than being discovered.

## How to execute this plan

Same two-phase ritual as Plans 1–6: **Phase A** writes the tests against the current stub (RED),
**Phase B** makes them pass. `RedisConfig` changes, so no `composer dump-autoload` is strictly
required, but run it anyway before the suite if any new class appears — 9.1 adds none.

Phase A's live-server half is the important half, and it is why this plan needs the integration
test to be real: a registry in Redis cannot be unit-tested into existence. `phpunit.xml` sets
`failOnSkipped="true"`, so a skipped integration test is a failed run — which is the correct
outcome outside the container, where the `redis` host does not resolve.

---

## 9.1 `RedisConfig` gains the lease length

**Location:** `src/Adapter/Redis/RedisConfig.php:49-60`

One new optional field, appended last so every existing named-argument construction keeps working,
with the same validation style as its neighbours (`InvalidArgumentException` naming the field, at
construction, not against the server):

```php
    public const int WORKER_TTL_MIN = 5;
    // ...
    /**
     * @param int $workerTtl seconds a worker lease is valid for; the registry is only as
     *                         accurate as this number, so it must exceed the longest job
     */
    public function __construct(
        public string $host = '127.0.0.1',
        // ... the ten existing fields, unchanged
        public string $queueName = 'default',
        public int $workerTtl = 300,
    ) {
        // ...
        if ($workerTtl < self::WORKER_TTL_MIN) {
            throw new InvalidArgumentException(
                'workerTtl must be at least ' . self::WORKER_TTL_MIN . ' seconds, got ' . $workerTtl
            );
        }
    }
```

The `JobConfig`-style validation is not decoration: a `workerTtl` of 0 would make every worker look
absent, and a `workerTtl` of 1 would make the registry flap. Both are silent, both are a support
ticket. The default of 300 s is a deliberate choice of "longer than most jobs" over "frequently
fresh": the cost of being wrong is a false negative, and a false negative is free on a publish
path.

Only **one** knob. The renew interval is derived (`max(1, intdiv($ttl, 3))`, see 9.3) rather than
configured, because a second number the user can set wrongly is a second thing to document.

## 9.2 The key, the token and the three Redis words

**Location:** `src/Adapter/Redis.php` — three constants beside `CONNECTION_NAME` (`:69`)

```php
    private const string CONNECTION_NAME  = 'redis1';
    private const string REDIS_DRIVER     = 'phpredis';
    private const string REDIS_DRIVER_OWN = 'redis-backq';

    /**
     * The registry key, before phpredis applies the configured prefix
     *
     * The prefix is applied by the connection, not here: a plain ZADD/ZREM/ZCARD on this name
     * lands as {prefix}backq:workers:{queue}. Pass an already-prefixed name and it lands twice,
     * which is why this method never concatenates $this->config->prefix.
     */
    private const string WORKERS_KEY = 'backq:workers:';

    /**
     * Renew the lease this often, as a fraction of its length: a worker that dies is reaped
     * within workerTtl, and a worker that renews late still survives its own lease.
     */
    private const int WORKER_RENEW_DIVISOR = 3;
```

plus one private property and three private helpers. The token identifies *this adapter instance*
and must not be a class name, a hostname or a `getmypid()` alone — two workers in one process (a
supervisor running two queues) share a pid:

```php
    /**
     * This adapter's lease token, generated once on first use
     *
     * A publisher serialized through a queue drops its adapter (AbstractPublisher::__sleep), so
     * a token is never serialized and never has to be. A publisher never binds read, so it never
     * takes a lease at all.
     */
    private ?string $workerToken = null;
```

```php
    /**
     * The server's clock, in seconds
     *
     * The lease is an absolute expiry and it is compared against the server's own clock on both
     * sides, so two hosts with skewed clocks cannot make a dead worker look alive. phpredis
     * answers TIME as two strings, in seconds and microseconds, so the cast and the index are
     * both load-bearing.
     *
     * A TIME that does not answer with a timestamp is a protocol break, not a slow server: it is
     * raised inside the attempt() helper of the caller, so it is logged and that operation
     * answers false.
     */
    private function serverNow(Factory|\Redis $redis): int
    {
        assert($redis instanceof \Redis);
        $time = $redis->time();
        if (!isset($time[0])) {
            throw new RuntimeException('redis TIME did not answer with a timestamp');
        }

        return (int) $time[0];
    }

    /**
     * The connection, as the command surface every Redis word in this class goes through
     *
     * The union is what the value really is: getRedis() is declared Factory and answers a
     * BackQ\Adapter\Redis\Manager at runtime, which is one. A narrower native type here is
     * not a stricter check but a TypeError on the first lease a real deployment takes.
     *
     * A caller narrows it with one assert, exactly as pingServer() does: the assert is what
     * the static analysers read, and the Manager's __call is what actually runs. The assert
     * is compiled out (`build/php.ini:19` sets zend.assertions=-1), so do not turn it into a
     * real check, and do not add a second narrowing style next to pingServer()'s.
     */
    private function redis(): Factory|\Redis
    {
        $redisQueue = $this->queue()->getConnection(self::CONNECTION_NAME);
        assert($redisQueue instanceof Queue);

        return $redisQueue->getRedis();
    }

    // and at each call site, e.g. inside the attempt() closure of hasWorkers():

        $redis = $this->redis();
        assert($redis instanceof \Redis);
        $now = $this->serverNow($redis);
```

and the registry word itself, which is also where "never load-bearing" is enforced:

```php
    /**
     * Take or renew this adapter's lease on the queue, and return whether Redis took it
     *
     * The answer is not used by the work cycle: a worker that cannot write the registry must
     * still work, or a Redis ACL that permits LPUSH and not ZADD takes down every worker. A
     * failure is logged by attempt() and otherwise ignored, so hasWorkers() degrades to "no
     * workers" and nothing else changes.
     */
    private function lease(string $queue): bool
    {
        $token = $this->workerToken ??= getmypid() . '-' . bin2hex(random_bytes(8));

        /**
         * attempt() gates on isReady(), which is true here: only a bound adapter reaches this.
         * Its false is the answer for "Redis did not take the lease".
         */
        return $this->attempt('lease', function () use ($queue, $token): bool {
            $score = $this->serverNow() + $this->config->workerTtl;
            $added = $this->redis()->zadd(self::WORKERS_KEY . $queue, $score, $token);

            /**
             * zadd answers how many members were *new*: 1 on the first announce, 0 on a renew,
             * and 0 again if the key was flushed in between. Both are success, so testing for
             * `1 === $added` would log a failure on every heartbeat after the first.
             */
            return 0 === $added || 1 === $added;
        });
    }
```

If phpstan or psalm resolves `zadd` as `mixed` — it may, since neither `Manager` nor
`Illuminate\Contracts\Redis\Factory` declares such a method and the call goes through `__call` — the
fix is a shape check that raises, never a silent `false`:

```php
            $added = $this->redis()->zadd(self::WORKERS_KEY . $queue, $score, $token);
            if (!is_int($added)) {
                throw new RuntimeException('redis ZADD did not answer with a member count');
            }
```

A silent `false` here would be the worst outcome available: a protocol change would turn
`hasWorkers()` into "no worker has ever existed", with nothing in the log and no failing test. The
raise lands inside `attempt()`, which logs it at `error` with `['exception' => $e]`.

`random_bytes` and `getmypid` need no import: they are global functions used unqualified, which is
what `ReferenceUsedNamesOnly` with `allowFullyQualifiedGlobalFunctions="false"` asks for, and
`ensureConnected()` already calls `getmypid()` that way.

## 9.3 `bindRead()` announces, `disconnect()` releases

**Location:** `src/Adapter/Redis.php:589-602` and `:255-310`

Same hook as Plan 8, in `bind()`, after `$this->state = $role`, and only for
`ConnectionState::BindRead` — a publisher is not a worker:

```php
        if (ConnectionState::BindRead === $role) {
            $this->lease($queue);
        }
```

and at the top of `disconnect()`, before the `state !== Nothing` check and before the `try`, so a
throwing release loop cannot skip it. The release is best effort and its answer is discarded, for
the same reason the announce's is: `disconnect()` reports whether *this adapter* is unbound, and a
registry it could not write to is not that adapter's problem:

```php
        /**
         * Give the lease back so a publisher does not wait out the TTL on an adapter that is
         * gone. Best effort: the lease expires on its own, and disconnect() answers about this
         * adapter's own socket, not about the registry.
         */
        $this->release($this->queueName);
```

with the matching helper, mirroring `lease()`:

```php
    /**
     * Give the lease back, and do not care whether Redis took it
     *
     * An adapter that never announced has no token and nothing to release, which is the normal
     * case for a publisher: it binds write, so it never took a lease.
     */
    private function release(string $queue): void
    {
        if (null === $this->workerToken) {
            return;
        }

        $this->attempt('release', function () use ($queue): bool {
            $this->redis()->zrem(self::WORKERS_KEY . $queue, $this->workerToken);

            return true;
        });
    }
```

**Why the renew is bounded.** The renew is `intdiv($this->config->workerTtl, 3)` seconds, and it
happens *before* `pop()` in `pickTask()`. The gap between two renews is therefore
`interval + blockFor + job duration + ack`. `blockFor` is not unbounded: `setWorkTimeout()`
(`:207-250`) clamps it to `readTimeout - 1`, and `RedisConfig` validates `readTimeout >= 1`, so the
pop cannot hold a lease past `workerTtl / 3 + readTimeout`. With the defaults
(`readTimeout: 10`, `workerTtl: 300`) that is 9 + 100 = 109 s of a 300 s lease, leaving room for a
job of 190 s. A caller who sets `readTimeout: 600` must raise `workerTtl`; say so in the field's
docblock, and note that the clamp in `setWorkTimeout()` is what makes the arithmetic work at all.

## 9.4 `pickTask()` renews, at most every `ttl / 3` seconds

**Location:** `src/Adapter/Redis.php:388-473`, inside the `attemptRethrowing` closure, immediately
before `$redisQueue->pop(...)`

```php
        $result = $this->attemptRethrowing($operation, function () use ($operation): bool|array {
            $redisQueue = $this->queue()->getConnection(self::CONNECTION_NAME);
            assert($redisQueue instanceof Queue);
            if ($this->blockFor) {
                $redisQueue->setBlockFor($this->blockFor);
            }

            /**
             * Renew before the pop, not after: the pop blocks for up to readTimeout seconds, and
             * a lease that expires while the worker is waiting is a worker reported absent while
             * it waits for exactly the job being published.
             */
            $this->renew();

            // ... unchanged
        });
```

and the interval bookkeeping, the second piece of adapter state this plan adds:

```php
    /**
     * When this adapter last renewed its lease, in this process's own seconds
     *
     * Null until the first renew, so the first pick cycle after bindRead() writes one. This is
     * a duration between two events in one process and nothing else compares it, so PHP's clock
     * is the right one here; the *score* the lease carries is the server's clock, and that is
     * serverNow()'s job.
     */
    private ?int $leasedAt = null;

    /**
     * Renew the lease if it is due, and do nothing if it is not
     *
     * Every pick cycle runs this, so it is rate-limited here rather than in the caller. The
     * interval is a third of the lease, so a worker that is late once is still covered twice over.
     * Cheap because pickTask() is itself rate-limited by the blocking pop; the only case this
     * guards is a polling worker with blockFor = null, where an extra command per cycle would
     * roughly double the round trips of an idle loop.
     */
    private function renew(): void
    {
        $interval = max(1, intdiv($this->config->workerTtl, self::WORKER_RENEW_DIVISOR));
        $now = time();
        if (null !== $this->leasedAt && ($now - $this->leasedAt) < $interval) {
            return;
        }

        if ($this->lease($this->queueName)) {
            $this->leasedAt = $now;
        }
    }
```

Two details: the PHP clock is correct here, because the interval is a *duration* between two
renewals in the same process and no other host compares it; the *lease* is the thing that must use
the server's clock, and `lease()` does. And `$this->leasedAt` starts as `null` so the first pick
renews immediately, which is what makes a freshly started worker visible one pick cycle after it
binds.

## 9.5 `hasWorkers()` reads the registry

**Location:** `src/Adapter/Redis.php:367-379`

`attempt()` is already this operation's helper in the contract table (`AbstractAdapter.php:42`),
and now there is I/O to fail. The `$operation` carry-in is the `connect()` trap from Plan 8, and
`isIdle()` from Plan 8 has no counterpart here: a lease cannot tell busy from idle.

```php
    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * The answer comes from the per-queue lease registry the workers write, so it spans
     * processes and hosts: this is the one method where a publisher sees workers it did not
     * start. What it measures is "a worker was seen on this queue within the last workerTtl
     * seconds" — a worker running a job longer than workerTtl is not reported, and a worker
     * that started a moment ago is not reported yet. Both are false rather than a wrong true, so
     * the answer is always safe to publish on.
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        /**
         * __FUNCTION__ is the string `{closure}` inside a closure, so the name is carried in for
         * the messages below to name.
         */
        $operation = __FUNCTION__;

        return $this->attempt($operation, function () use ($queue, $operation): bool {
            $key = self::WORKERS_KEY . $queue;
            $now = $this->serverNow();

            /**
             * Prune first, count second: a worker killed with SIGKILL leaves its lease behind,
             * and only the prune is what makes the count mean "alive right now". Three commands
             * counting the TIME where a Lua script would be one — see the design section for why
             * the script is not worth the caveat it comes with.
             */
            $this->redis()->zremrangebyscore($key, '-inf', $now);
            $workers = $this->redis()->zcard($key);

            $this?->logger->debug($operation . ': ' . $workers . ' worker(s) with a live lease on ' . $queue);

            return $workers > 0;
        });
    }
```

`zcard` returns `int` through the `Manager`, so `$workers > 0` needs no cast; if phpstan disagrees
because `__call` is `mixed`, the fix is a documented `is_int()` check that throws inside the
`attempt()` helper — not a silent `return false`, which would turn a protocol change into "no
workers".

`isReady()` still gates the whole thing, so a publisher that never called `start()` answers
`false` exactly as the stub did.

## 9.6 Tests

**Location:** `tests/Adapter/RedisAdapterCoreTest.php` (offline, 39 tests today) and
`tests/Adapter/RedisAdapterTest.php` (live server, 1 test today)

The offline half replaces `testHasWorkersReportsNotSupported()` (`:202-207`) and pins the
mechanics, using the existing `wireManager()` (`:508-514`) plus a mocked `\Redis`:

| Test | Pins |
|---|---|
| `testBindReadAnnouncesALease` | `zadd` on the prefixed key, member = token, score = `serverNow + workerTtl` |
| `testBindWriteDoesNotAnnounceALease` | a publisher is not a worker |
| `testDisconnectReleasesTheLease` | `zrem` with the same token |
| `testTheLeaseIsNeverLoadBearingForAWorkCycle` | `zadd` throws → `bindRead()` still returns `true`, and the throwable is logged at `error` |
| `testPickTaskRenewsTheLeaseAtMostEveryTtlOverThree` | two consecutive picks → one `zadd` |
| `testHasWorkersCountsTheLeasesItDidNotWrite` | `zcard` = 2 → `true` |
| `testHasWorkersPrunesExpiredLeasesBeforeCounting` | `zremrangebyscore` before `zcard`, in that order |
| `testHasWorkersIsFalseOnAnUnboundAdapter` | the `attempt()` precondition |
| `testHasWorkersIsFalseWhenRedisDoesNotAnswer` | `time()` throws → `false`, logged at `error` |

The load-bearing test is the fourth. A registry that can fail a worker is worse than no registry:

```php
    /**
     * A worker that cannot write the registry must still work: a Redis ACL that permits the
     * queue commands and not ZADD must not take down every worker in the deployment
     */
    public function testTheLeaseIsNeverLoadBearingForAWorkCycle(): void
    {
        $logger = new RecordingLogger();
        $redis  = new Redis($logger);

        $redisClient = $this->createMock(\Redis::class);
        $redisClient->method('ping')->willReturn('+PONG');
        $redisClient->method('time')->willThrowException(new \RedisException('NOPERM this user has no permissions'));
        $this->wireManager($redis, $this->queueAnswering($redisClient));

        $this->assertTrue($redis->connect());

        /**
         * The whole point: the lease failed, the bind did not. attempt() logged it and the work
         * cycle never saw it.
         */
        $this->assertTrue($redis->bindRead('queue'));
        $this->assertLogged($logger, 'adapter lease exception: NOPERM', 'error');
    }
```

**The live half is the only one that can prove the feature is distributed.** Two adapters in one
PHP process are not a distributed registry — they are a shared Redis, which is the thing being
tested:

```php
    public function testHasWorkersSeesAWorkerInAnotherProcess(): void
    {
        // ... the reachability guard the existing test already has (:31-42)
        $queue  = 'backq.test.' . uniqid();
        $config = new RedisConfig(host: $host, port: $port);

        $worker = new Redis(new NullLogger(), $config);
        $this->assertTrue($worker->connect());
        $this->assertTrue($worker->bindRead($queue));

        /**
         * A second connection to the same server, the way a publisher in a web request has one:
         * it has never bound read, so the only thing that can answer it is the registry the
         * worker wrote.
         */
        $publisher = new Redis(new NullLogger(), $config);
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite($queue));
        $this->assertTrue($publisher->hasWorkers($queue));

        $worker->disconnect();
        $this->assertFalse($publisher->hasWorkers($queue));
    }
```

Add two more against the same server, because these are the failure modes the design has and only
Redis can show them:

- **the key is where the plan says it is.** A raw `\Redis` client (no prefix configured) asserts
  the member exists under `{prefix}backq:workers:{queue}` and not under the bare name. Without
  this, a prefixing change in illuminate or phpredis is invisible until the feature silently
  answers `false` forever. Clean the key up in a `finally` — `phpunit.xml` forbids a skipped test,
  and a leaked key outlives the suite.
- **a stale lease is reaped.** Write a member with score `serverNow - 1` by hand, then assert
  `hasWorkers()` is `false` *and* that the member is gone. This is the SIGKILL case, and it cannot
  be produced by two adapters in one process, because `disconnect()` always runs.

Neither the offline nor the live half can prove that the answer survives a real process kill.
Say so in the PR description rather than implying the tests cover it; a `pcntl_fork` + `posix_kill`
test would be the honest version and is not worth the flakiness on a shared CI runner.

## 9.7 Docs

- **`README.md:167`** — `hasWorkers` goes from `*` to `✓` for Redis, and the bullet at `:171` is
  replaced by what the answer measures: "reported from a per-queue lease registry, so it spans
  processes and hosts; it means *seen within `workerTtl` seconds*, so raise `workerTtl` above your
  longest job". Also mention the `backq:workers:{queue}` key and that `RedisQueue::clear()` does
  not remove it, because an operator reading a `KEYS` dump will ask.
- **`UPGRADING:58-63`** — amend the existing "`Redis` reports 'no workers' (a documented stub
  contract)" clause in place, and record the two observable consequences: the method performs Redis
  I/O now, so it can log an `error` where it used to be silent, and `RedisConfig` gained an
  eleventh field.
- **New configuration is public API**, so this is the one item in either plan that needs an
  `UPGRADING` line of its own. `RedisConfig` is `readonly` with defaults, so no existing
  construction breaks.

## Verification

```bash
task=$(cat <<'EOF'
cd /app || exit 1
php -l src/Adapter/Redis.php
php -l src/Adapter/Redis/RedisConfig.php
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s \
  src/Adapter/Redis.php src/Adapter/Redis/RedisConfig.php tests/Adapter/RedisAdapterCoreTest.php \
  tests/Adapter/RedisAdapterTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon \
  src/Adapter/Redis.php src/Adapter/Redis/RedisConfig.php
php psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true \
  src/Adapter/Redis.php src/Adapter/Redis/RedisConfig.php
php -d memory_limit=-1 vendor/bin/phpunit --configuration=phpunit.xml --filter RedisAdapterCoreTest
php -d memory_limit=-1 vendor/bin/phpunit --configuration=phpunit.xml
grep -c 'psalm-suppress' src/Adapter/Redis.php
grep -n 'workerTtl' src/Adapter/Redis/RedisConfig.php src/Adapter/Redis.php
echo "SENTINEL: reached end"
EOF
)
docker exec app-php83 bash -c "$task"
```

Beyond the sentinel:

- **The live tests must not skip.** `failOnSkipped="true"` means a skipped `RedisAdapterTest` is a
  failed run, which is what should happen on a host where the `redis` service name does not
  resolve. In the container the service is up and the count of skips must be zero.
- **Test counts before and after**, attributed line by line. Measured, not predicted:
  `RedisAdapterCoreTest` 39 → 48 (one replaced, **ten** added — the tenth, the registry-is-empty
  case, is in "Corrections" below), `RedisAdapterTest` 1 → 4 (three, not two),
  `RedisConfigTest` 16 → 21, full suite 385 → 402 tests and 981 → 1040 assertions. A drop means a
  deleted test.
- **No key left behind.** After the full suite, `redis-cli -n 0 keys 'backqtest*'` (or whatever
  prefix the tests use) must be empty. A leaked `backq:workers:*` key survives the suite and will
  make a later `hasWorkers()` test pass for the wrong reason — which is the exact failure mode
  Plan 7 §"Cost 4" warns about with stale fixtures.
- **`grep -c 'psalm-suppress' src/Adapter/Redis.php` was 5.** It is now **7**, and the `AGENTS.md`
  count across `src/Adapter/` moved 12 → 14, both measured and both written back. The two new ones
  are **not** the `?->logger` trio this file already carries: they are
  `@psalm-suppress TypeDoesNotContainType` on the two `is_int()` shape checks over `ZADD` and
  `ZCARD`, because psalm resolves both commands as always answering `int` while phpredis declares
  `Redis|int|false`. That is the general shape of the count: a runtime guard on a value psalm has
  already narrowed to one type needs its own suppression. Measure and attribute; never add one to
  silence a question without reading it.
- **`composer dump-autoload` is not needed** — 9.1 adds a field, not a class. If a helper ends up
  as a new class instead, it is needed, and its absence shows up as a load-time fatal that
  `check-classes.php` catches first.

## Traps

- **`time()` returns two strings.** `(int) $time[0]` or the score is `0` and every lease is already
  expired. Measured on 6.3.0: `["1790613830","887094"]`.
- **`zadd` returns *new* members.** `1 === zadd(...)` is false on every renew after the first, so
  the naive version logs a failure per heartbeat. See 9.2's second sketch.
- **The prune must come before the count**, or a killed worker's lease is reported until the next
  prune happens to run. Order is also asserted in a unit test.
- **Do not prefix the key by hand.** phpredis applies the configured prefix; concatenating
  `$this->config->prefix` into the key name writes to a doubly-prefixed key and `hasWorkers()`
  returns `false` forever. `WORKERS_KEY` is a constant for exactly this reason.
- **`getRedis()` is a `Manager`, not a `\Redis`.** The `assert()` is what the analysers read and
  `__call` is what runs; a real `instanceof` check would fail at runtime on every call.
- **Renew before the pop, not after.** A worker blocked in `blpop` is a worker waiting for exactly
  the job being published; a lease that expires during the block reports it absent.
- **The registry must never be load-bearing.** §9.2, and the test that proves it.
- **`__FUNCTION__` inside a closure is `{closure}`.** Carry the name in, as `connect()` does
  (`Redis.php:545-548`).
- **A worker that never reaches `pickTask()` announces once and then goes quiet** — the release in
  `disconnect()` and the TTL are the only things that remove it. That is correct; a worker blocked
  forever is a worker the operator has bigger problems with.
- **`getmypid()` answers `int|false`**, so it cannot be concatenated into a token directly. The
  pid is decorative — the random half is what makes a token unique — so `is_int($pid) ? $pid : 0`
  is the whole fix. This is the third time in this feature that psalm caught a value phpredis
  types more honestly than the concatenation assumed.
- **The tenth offline test this plan listed does not fit its own list.** The nine tests it named
  include three ways of answering `false` and none of them is *the registry is empty*, which is the
  case that replaces `testHasWorkersReportsNotSupported()` and the one that a "always answer true"
  bug would sail through. It is now
  `testHasWorkersIsFalseWhenNoWorkerHasALease`, and it is why the count is ten, not nine.

## Corrections this plan needed

The design survived implementation unchanged. Four things in the sketches did not, all of them
found by the container and recorded here because the sketch is what a future reader copies:

1. **`redis(): Manager|\Redis` is a `TypeError`, not a narrower check.** `getRedis()` is declared
   `Illuminate\Contracts\Redis\Factory`, which a `Manager` is — but the sketch's native return
   type made it *narrower* than reality while the assert inside implied it was wider. The first
   live test failed with
   `Return value must be of type Redis, BackQ\Adapter\Redis\Manager returned`, and because
   every offline test wires a `\Redis` mock, **the whole 48-test offline suite stayed green
   while the feature was completely broken in production.** A native type on a value that is a
   `Manager` at runtime is a runtime check, not a documentation device. Shipped as
   `Factory|\Redis` with the assert at each call site.
2. **The same trap has a second entrance: a parameter.** Hoisting `serverNow()`'s connection out
   to remove a duplicate lookup made it `serverNow(\Redis $redis)`, which is the identical native
   check in a different place. The three offline tests around it went green and the three live
   ones went red. `serverNow(Factory|\Redis $redis)` with its own assert inside is what shipped.
   **Any native type on a value taken from `getRedis()` must be the union.**
3. **`Manager` is not a free name in this file.** `use Illuminate\Queue\Capsule\Manager;` is
   already there, so the sketch's `Manager|\Redis` resolved to `BackQ\Adapter\Manager`, which
   does not exist. `php -l` passes it and `check-classes.php` does not catch it either, because an
   unknown class in a union is only checked at call time. The file's own convention, already used
   by `disconnect()`, is `Redis\Manager`.
4. **psalm needs `TypeDoesNotContainType`, not `RedundantCondition`,** for the `is_int()` guards —
   the suppression that says "this looks dead" is named after the message it silences, and the
   first attempt with the wrong name silenced nothing. Read the issue name off the output.

## If you take both plans

Plan 8's registry and this one are answers to the same method, so the composition is one line in
`hasWorkers()`:

```php
return $this->localHasWorkers($queue) || $this->leasedHasWorkers($queue);
```

Worth doing only in one order: **9.1–9.4 first, then 8.4 on top** (the local answer is the cheap
one and it is exact, so it short-circuits the round trip whenever it can), and never the reverse —
`hasWorkers()` must not cost a Redis round trip when the answer is already in the process. What
survives unchanged from Plan 8 in that case: `queueIdentity()` (the same `RedisConfig` fields name
the identity and the key), the `bindRead()`/`disconnect()` hooks, and the `attempt()` wrapper. What
does not: `WorkerRegistry` itself, and `isIdle()`, which a lease cannot express.
