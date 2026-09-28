# Plan 8 — `Redis::hasWorkers()` from a process-local worker registry

> Status: **proposed, not started.**
> Scope: `Redis::hasWorkers()` (`src/Adapter/Redis.php:374`), the three methods that register and
> release a worker (`:255` `disconnect()`, `:589` `bind()`), one new class
> `src/Adapter/Redis/WorkerRegistry.php`, the two Redis test files, the `README.md` feature table
> and the `UPGRADING` bullet that currently calls the method a stub.
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-2-network-ssl-disconnects.md`,
> `plan-3-connection-liveness-resilience.md`, `plan-4-minor-notes-behavior.md`,
> `plan-5-put-task-contract.md`, `plan-6-error-log-deprecation.md`,
> `plan-7-adapter-architecture.md`.
>
> **The alternative to this plan is `plan-9-redis-has-workers-heartbeat.md`**, which answers the
> same question from Redis instead of from the process. The two are alternatives, not steps; the
> "If you take both" section at the end says which pieces of this one survive either way.
>
> **Read this first:** `hasWorkers()` is on `QueueProducer`, so it is a *publisher* call. A
> publisher and a worker are normally **different processes** — a web request publishing, a
> supervisor-driven worker consuming. A process-local registry therefore answers "yes" only for
> the topology where a publisher and a worker share a process. This plan is honest about that and
> fixes the lie in the stub; it does **not** give a web publisher visibility into other machines.
> If that is the requirement, take Plan 9 instead — this one is then the wrong plan, not a
> first step.

## The problem

`Redis::hasWorkers()` is the only method in the adapter that answers from a constant, and the
constant is `false`:

```php
    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * Redis has no concept of "worker availability" for a queue when using the
     * underlying list-based jobs, so this always reports "no workers".
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        $this?->logger->debug(self::class . '.' . __FUNCTION__ . ' not supported, reporting no workers');

        return false;
    }
```

`Beanstalk` answers it for real (`statsTube` → `current-watching`, `Beanstalk.php:111-143`), so a
caller that programs to the eleven-method contract gets a working feature on one backend and a
permanently-false one on another. Three things follow, in ascending order of cost:

1. **The method is unreachable as a feature.** `README.md:167` marks it `*` and `:171` says it is a
   stub, so nobody builds on it.
2. **The log line is a non-sequitur.** "not supported" is a claim about the *backend*, and it is
   false as written: what is unsupported is this *implementation*. Redis is perfectly capable of
   saying whether a client of its own is alive.
3. **`false` is read as an answer, not as a refusal.** The one caller in the library,
   `AbstractPublisher::hasWorkers()` (`src/Publisher/AbstractPublisher.php:117-120`), forwards it
   verbatim. A user who writes `if (!$publisher->hasWorkers()) { /* nobody is listening *\/ }`
   gets no way to tell "there is genuinely nobody" from "this adapter cannot know".

The second half of the fix is the interesting half: the adapter **is** holding the information.
A worker adapter in this process is bound read (`ConnectionState::BindRead`) and its `reservedJobs`
array tells you whether it is sitting on a job right now. Both facts are already in the object, and
nothing reads them.

## The design

`hasWorkers()` answers about **the worker adapters registered in this process**, per queue, and
reports `true` only when at least one of them is bound read, bound to that queue, on the same Redis
target, and currently holding no job.

Three properties make this cheap and, more importantly, make it *correct without a liveness
protocol*:

- **Liveness is free.** A dead process cannot hold a PHP object. If a registered adapter is still
  reachable, its process is still running. A worker killed with `SIGKILL` takes its static state
  with it, so there is no stale entry to reap — no TTL, no heartbeat, no clock, no sweeper.
  The registry is a `WeakMap` for the same reason: an adapter the caller dropped is collected and
  its entry goes with it.
- **The state is the authority, not the entry.** `disconnect()` removes the entry, but even a
  missed removal cannot produce a wrong `true`: a disconnected adapter's `state` is
  `ConnectionState::Nothing`, and the state is what `hasWorkers()` asks. Deregistration is there
  to keep the map small and to give a test something deterministic to assert on.
- **It is false-biased.** The only way to answer `true` is to hold a live reference to an idle
  read-bound adapter. Every other outcome is `false`, so the method can never claim a worker that
  is not there. For a publish path that is the correct bias: `false` means "publish anyway".

**Identity.** A `Redis` adapter in the same process may point at a different server, database or
prefix, and a worker on `redis-a:6379/0` is not a worker for a publisher on `redis-b:6379/0`. The
registry is therefore only consulted for entries whose adapter reports the same identity:

```
host . ':' . port . '/' . databaseId . '/' . (prefix ?? '')
```

This is derived from `RedisConfig` (`src/Adapter/Redis/RedisConfig.php`), which is `readonly`, so
the string cannot drift from the configuration it names. Note that the *prefix* is part of the
identity, not of the key: a publisher with a different prefix is a different queue namespace
entirely, so a worker behind one prefix is invisible to a publisher behind another.

**Busy is not ready.** A worker holding a job is not "ready to work immediately", and the adapter
knows: `reservedJobs` is non-empty for exactly that period. Counted as ready only when it is
empty, which is a stricter and more useful answer than "some process is bound to this queue".

### Measured, not assumed

Every claim above that can be checked was checked in `app-php83` (PHP 8.3.33, phpredis 6.3.0,
`build/php.ini` has `zend.assertions=-1`, so `assert()` in `src/` is compiled out at runtime):

| Question | Answer | How |
|---|---|---|
| Does a `WeakMap` entry survive its key being collected? | no | probe: `unset($o); gc_collect_cycles();` → `count($wm) === 0` |
| Does a value that strongly references its key keep the entry? | no (8.3.33) | value = an object holding the key, and a closure capturing it: `0` in both |
| Can one instance read a `private` member of another instance of the same class? | yes | `$a->peek($b)` reading `$b->private $secret` → `shh` |
| Is `($x ??= new WeakMap())[$k] = $v` legal? | **no** | `Error: Cannot use temporary expression in write context` |
| Is `unset(self::$weakMap[$key])` safe while the map is `null`? | yes | no warning, no error |
| Is `hasWorkers()` inside the `attempt()` precondition? | yes | `AbstractAdapter.php:42`, `:200-209`, and `isReady()` is `state->isBound()` |

The third row is what keeps the new class honest: `WorkerRegistry` cannot read `Redis`'s private
state, but `Redis::hasWorkers()` **can** read another `Redis` instance's private state, because
private visibility is class-scoped, not instance-scoped. So the filtering stays in `Redis` and the
registry stays a dumb store with no accessor of its own beyond the queue name it was registered
with. The second row is not relied on — the registered value is a `string`, so the value cannot
reference the key at all.

## Out of scope (considered and deliberately excluded)

- **A tri-state answer.** `bool` is the contract (`AbstractAdapter.php:149`, `QueueProducer.php:60`,
  and `UPGRADING:58` records the narrowing to `bool` for 5.x). "unknown" would need a new
  signature on two interfaces, two adapters, and every third-party adapter. This plan keeps
  `bool` and puts the scope in the docblock, the README and the log line.
- **Generalising the registry to `MySql`.** A process-local registry would work for `MySql` too, and
  Plan 7 §4.4 left `MySql::hasWorkers()` answering `false` for exactly this reason. But `MySql`'s
  adapter is handed a caller-owned `mysqli` and a table shared by every process, so a
  process-local answer is *more* misleading there (it would say "no workers" whenever the answer
  is unknowable, in a backend whose feature table already promises nothing). One adapter, one
  registry; do not generalise until a second adapter has a use for it.
- **Touching `AbstractWorker`.** A worker is a `QueueConsumer`; it never calls `hasWorkers()`. The
  registration is an adapter-internal consequence of `bindRead()`, so the worker needs no change
  and a user-supplied adapter keeps working.
- **Counting.** `bool` is the return type, so a count is thrown away even if the registry knows
  it. Adding `workerCount()` is new public API for a method nobody in the library calls; not now.
- **`CLIENT LIST` (a genuine third option, and rejected).** Redis can report the connected clients
  and what each is blocked on, so "is anyone blocked on `BLPOP queues:{q}:notify`" is answerable
  with no writes at all — and it is a *remote* answer, which is what Plan 9 buys. It is rejected
  for three reasons: it needs a permission many managed Redis services withhold, it is `O(number
  of clients)` on a shared server, and it only sees a worker in the moment it is blocked — a
  worker polling with `blockFor = null` (a supported configuration, `Redis.php:207-250`) is
  invisible to it, so it answers `false` for a configuration the library actively supports. It
  also cannot answer the `isIdle()` half, which is free in this plan.

## How to execute this plan

Two phases, the same ritual as Plans 1–6: **Phase A** writes the tests against the current stub
(RED), **Phase B** makes them pass. A new class lands in between, so `composer dump-autoload` runs
inside the container before anything else — `classmap-authoritative` is on and an un-dumped class
fails the suite with a load-time error rather than a test failure.

Phase A is the larger half here and Phase B is small, which is the right way round: it is what
proves the tests observe the registry rather than the stub.

---

## 8.1 `src/Adapter/Redis/WorkerRegistry.php` — the store

A new class, because `Redis.php` is 769 lines and `SlevomatCodingStandard.Classes.ClassLength` is
excluded but the file is already the largest in the directory. `final`, three static methods, no
state beyond the map:

```php
<?php

namespace BackQ\Adapter\Redis;

use BackQ\Adapter\Redis as RedisAdapter;
use WeakMap;

/**
 * The worker adapters this process has bound read, by queue
 *
 * The map is weak, so an entry cannot outlive the adapter it names: a worker process that dies
 * takes its entry with it, and an adapter the caller dropped is collected. That is the whole
 * liveness argument — there is no TTL to tune and no stale entry to reap.
 *
 * The value is the queue name and nothing else. A value that referenced the adapter would make
 * the entry self-referential, and the one thing this class must never do is keep a worker alive
 * by accident.
 */
final class WorkerRegistry
{
    /**
     * @var WeakMap<RedisAdapter, string>|null null until the first worker binds read
     */
    private static ?WeakMap $workers = null;

    /**
     * @param RedisAdapter $worker the adapter that just took the read role
     * @param string       $queue  the queue it is bound to
     */
    public static function register(RedisAdapter $worker, string $queue): void
    {
        /**
         * The assignment is on its own statement on purpose: `($x ??= new WeakMap())[$k] = $v`
         * is a compile error, "Cannot use temporary expression in write context".
         */
        self::$workers ??= new WeakMap();
        self::$workers[$worker] = $queue;
    }

    public static function deregister(RedisAdapter $worker): void
    {
        unset(self::$workers[$worker]);
    }

    /**
     * Every adapter still holding the read role on this queue, in no particular order
     *
     * A null map is a normal state, not an error: a process where no worker has ever bound.
     *
     * @return list<RedisAdapter>
     */
    public static function boundRead(string $queue): array
    {
        if (null === self::$workers) {
            return [];
        }

        $found = [];
        foreach (self::$workers as $worker => $boundQueue) {
            if ($boundQueue === $queue) {
                $found[] = $worker;
            }
        }

        return $found;
    }
}
```

Notes for the implementer, all of them load-bearing:

- `foreach (self::$workers as $worker => $boundQueue)` — iterating a `WeakMap` yields the key as
  the array key. phpstan and psalm both read this as `RedisAdapter` given the `@var` above; if
  either complains, type the accumulator instead of adding a `/** @var RedisAdapter $worker */`
  inside the loop.
- `WeakMap` is a global class and `ReferenceUsedNamesOnly` runs with
  `allowFullyQualifiedGlobalClasses="false"` (`build/phpcs-ruleset.xml:146-154`), so the `use
  WeakMap;` is required, not decorative. Same for `use function count;` if the method uses it —
  `allowFullyQualifiedGlobalFunctions="false"` means global functions are imported or written
  unqualified, never `\count()`.
- **`RedisAdapter` is an alias, and an import is required.** This file's namespace is
  `BackQ\Adapter\Redis`, so the class it needs is `BackQ\Adapter\Redis\Redis` as seen from
  elsewhere: an unqualified `Redis` here would resolve to the *namespace*, not the class. All three
  escapes were compiled in the container to confirm which is legal —
  `use BackQ\Adapter\Redis as RedisAdapter;` (the sketch's choice: unambiguous next to
  `Redis\Manager` and `RedisConfig`), an unaliased `use BackQ\Adapter\Redis;`, and the fully
  qualified `\BackQ\Adapter\Redis::class`. The first reads best; the second is legal and is a trap
  for the next reader; the third is what `ReferenceUsedNamesOnly` forbids.
- No `count()`-style API and no `reset()`: entries disappear with the adapters, and a test that
  needs a clean map drops its adapter (see 8.5), which is the same mechanism production uses.

## 8.2 `Redis::bind()` registers a read-bound adapter

**Location:** `src/Adapter/Redis.php:589-602`

`bind()` is the only place a role is taken — `bindRead()` and `bindWrite()` are two-line wrappers
around it (`:352-367`) — so it is the only place a worker can appear, and there is no second path
to miss. Register after the state is stored, so a `WorkerRegistry::register()` that throws (it
cannot, but the ordering says so) leaves an adapter that is not yet claiming a role:

```php
        $this->queueName = $queue;
        $this->ensureConnected();
        $this->state = $role;

        if (ConnectionState::BindRead === $role) {
            /**
             * A read-bound adapter is a worker, and this is the only method that makes an
             * adapter one, so this is the only place a worker is registered.
             */
            WorkerRegistry::register($this, $queue);
        }

        return true;
```

`ConnectionState::BindWrite` is not registered: a publisher is not a worker, and a
publisher-side `hasWorkers()` must not be able to find itself.

## 8.3 `Redis::disconnect()` deregisters

**Location:** `src/Adapter/Redis.php:255-310`

Deregistration goes **before** the `state !== Nothing` check, so it runs on both return paths, and
before the `try`, so a throwing release loop cannot leave the entry behind. A stale entry is
harmless (§"The state is the authority"), so this is about map size and testability, not
correctness — the comment should say so, or a future reader will "optimise" it away:

```php
    #[Override]
    public function disconnect(): bool
    {
        $this?->logger->debug('Disconnecting');

        /**
         * Not a worker from here on, whatever happens below: the answer hasWorkers() gives is
         * read from the state, so a missed deregistration could not make it lie, but a map that
         * only grows is a map that has to be explained.
         */
        WorkerRegistry::deregister($this);

        if (ConnectionState::Nothing !== $this->state) {
            // ... unchanged
        }
```

**Do not add a `__destruct`.** A destructor would run on `exit` paths and during shutdown in an
order the library does not control, and the weak map already handles collection. `grep -n
'__destruct' src/Adapter/` must return nothing after this item.

## 8.4 `Redis::hasWorkers()` — the answer, and two private helpers

**Location:** `src/Adapter/Redis.php:367-379`

`attempt()` is the helper the contract table already assigns to this operation
(`AbstractAdapter.php:42`), so it stays; it also gates on `isReady()`, which answers `false` for a
publisher that never called `start()` — the same answer the stub gave, for a better reason.

```php
    /**
     * Checks (if possible) if there are workers to work immediately
     *
     * The answer is about this process: it is `true` when an adapter in it is bound read on
     * this queue, on this Redis target, and is holding no job. A worker in another process is
     * invisible here, and a publisher in a web request will normally see none — the method says
     * so at `debug` level rather than reporting a number it cannot know. `false` therefore means
     * "publish anyway", never "do not publish".
     */
    #[Override]
    public function hasWorkers(string $queue): bool
    {
        /**
         * Inside a closure __FUNCTION__ is the string `{closure}`, so the operation is carried in
         * for the messages below to name, exactly as connect() does.
         */
        $operation = __FUNCTION__;

        return $this->attempt($operation, function () use ($queue, $operation): bool {
            $identity = $this->queueIdentity();

            foreach (WorkerRegistry::boundRead($queue) as $worker) {
                if ($worker->queueIdentity() !== $identity) {
                    continue;
                }

                if ($worker->isIdle()) {
                    $this?->logger->debug($operation . ': a worker is bound read and idle on ' . $queue);

                    return true;
                }
            }

            $this?->logger->debug(
                $operation . ': no idle worker is bound read on ' . $queue . ' in this process'
            );

            return false;
        });
    }

    /**
     * Which Redis this adapter talks to, as a comparable string
     *
     * Two adapters in one process can point at different servers, and a worker on one of them is
     * not a worker for the other. RedisConfig is readonly, so this cannot drift from the
     * configuration it names. The prefix is in the identity rather than the key because a
     * prefixed adapter is a different key namespace: a worker behind one prefix cannot take a
     * job for a publisher behind another.
     */
    private function queueIdentity(): string
    {
        return $this->config->host . ':' . $this->config->port
            . '/' . $this->config->databaseId
            . '/' . ($this->config->prefix ?? '');
    }

    /**
     * Is this adapter a worker with nothing to do right now
     *
     * A worker holding a job is busy, and a busy worker is not one a job can be handed to
     * immediately, which is the question hasWorkers() was asked. Reached from hasWorkers() on
     * another instance: private visibility is class-scoped, so the call is legal and the helper
     * stays out of the public API.
     */
    private function isIdle(): bool
    {
        return ConnectionState::BindRead === $this->state && [] === $this->reservedJobs;
    }
```

Four things a reviewer should check here:

1. `queueIdentity()` reads `$this->config`, a `private readonly` promoted property
   (`Redis.php:133`). Reading another instance's is the same class-scoped access as `isIdle()`.
2. `isIdle()` reads `$this->reservedJobs` and `$this->state` the same way. `assert()` is
   **not** used for either narrowing: the properties are typed, so there is nothing to narrow, and
   `zend.assertions=-1` means an assert would be documentation only.
3. A read-bound adapter asking about itself counts itself. That is the honest reading of "is there
   a worker ready" for a worker, and no code in the library asks.
4. **`__FUNCTION__` inside the closure is `{closure}`, not `hasWorkers`.** The name is carried in
   for exactly this reason, the way `connect()` does it (`Redis.php:545-548`, pinned by
   `RedisAdapterCoreTest::testTheConnectMessagesNameTheMethodAndNotTheClosure()` at `:138-148`).
   A `__FUNCTION__` written inside the closure compiles, passes every assertion in 8.5, and then
   writes `{closure}: a worker is bound read on …` into production logs.

## 8.5 Tests — Phase A, written against the stub

**Location:** `tests/Adapter/RedisAdapterCoreTest.php` (39 tests today, all offline)

The existing `testHasWorkersReportsNotSupported()` (`:202-207`) is the RED test and is **replaced**,
not kept: it asserts the behaviour this plan removes. The replacement set, each pinning one
decision above:

| Test | Pins |
|---|---|
| `testHasWorkersIsFalseWhenNoWorkerIsBound` | the empty registry, and that it is not an error |
| `testHasWorkersIsTrueWhenAWorkerIsBoundReadOnTheQueue` | the happy path, and that `bindRead()` is what registers |
| `testHasWorkersIsFalseForAnotherQueue` | the queue is part of the key |
| `testHasWorkersIsFalseWhenTheOnlyWorkerHoldsAJob` | `isIdle()` — set `reservedJobs` by reflection, `:524-527` |
| `testHasWorkersIsFalseWhenTheOnlyWorkerIsBoundWrite` | `bindWrite()` does not register |
| `testHasWorkersIsFalseAfterTheWorkerDisconnects` | 8.3, and the state authority |
| `testHasWorkersIgnoresAWorkerOnAnotherServer` | identity: build a second adapter with `new RedisConfig(host: 'other')` |
| `testHasWorkersIgnoresAWorkerBehindAnotherPrefix` | identity, prefix half |
| `testHasWorkersIsFalseOnAnAdapterThatNeverConnected` | the `attempt()` precondition, and the `debug` record `LogAssertions` reads |

Plus one test for the mechanism rather than the answer, because this is the property the whole
design rests on and no assertion above can see it:

```php
    /**
     * The registry is weak: an entry cannot outlive the adapter it names, so a dead worker
     * leaves nothing behind and no sweeper is needed
     */
    public function testTheRegistryForgetsAnAdapterThatIsNoLongerReferenced(): void
    {
        $worker = new Redis(new NullLogger());
        $this->wireManager($worker, $this->aliveQueue());
        $this->assertTrue($worker->connect());
        $this->assertTrue($worker->bindRead('the-queue'));

        $publisher = new Redis(new NullLogger());
        $this->wireManager($publisher, $this->aliveQueue());
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite('the-queue'));
        $this->assertTrue($publisher->hasWorkers('the-queue'));

        unset($worker);
        gc_collect_cycles();

        $this->assertFalse($publisher->hasWorkers('the-queue'));
    }
```

Without the `unset()`, this test passes for the wrong reason — the entry would still be there, and
so would the `true`. Run it once **without** the `unset()` and confirm it still answers `true`;
if it does not, the registry is not weak and the design is broken.

**No test reset is needed, and that is the point.** The registry is static, so a leftover entry
would leak into the next test in the same process. It does not: each test's adapter is dropped when
the test method returns, the weak map follows, and the next test starts with an empty registry. If
a future test needs a specific number of live workers, it must hold the adapters in the test — and
`phpunit --order-by=defects` will then be the thing that catches the missing `unset()`.

## 8.6 Docs

- **`README.md:167`** — the `Redis` row's `hasWorkers` cell goes from `*` to `✓`, and the bullet at
  `:171` is replaced by: "`Redis::hasWorkers()` reports the worker adapters bound in the **current
  process**; a publisher in another process, or on another host, is not seen." The `*` legend at
  `:170` stays, because `MySql` still needs it.
- **`UPGRADING:58-63`** — this is the *same bullet* that says "`Redis` reports 'no workers' (a
  documented stub contract)". Amend it in place rather than adding a second entry: `Redis` now
  reports the workers it can see, which are the ones in the same process. Call out the observable
  change for anyone who branched on the constant, and say the answer is still `false` across
  processes so no code that was written around the stub changes behaviour.
- **No new public API.** `queueIdentity()` and `isIdle()` are private; `WorkerRegistry` is
  `final` in a namespace a user is not expected to build against, and it is not mentioned in the
  README. This is the item that keeps the change inside a patch release, so if a reviewer wants
  `WorkerRegistry` documented as API, that is the signal to stop and reopen the question.

## Verification

Narrow, then wide, in the container, with the sentinel on the last line (AGENTS.md):

```bash
task=$(cat <<'EOF'
cd /app || exit 1
php -l src/Adapter/Redis.php
php -l src/Adapter/Redis/WorkerRegistry.php
composer dump-autoload
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s \
  src/Adapter/Redis.php src/Adapter/Redis/WorkerRegistry.php \
  tests/Adapter/RedisAdapterCoreTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon \
  src/Adapter/Redis.php src/Adapter/Redis/WorkerRegistry.php
php psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true \
  src/Adapter/Redis.php src/Adapter/Redis/WorkerRegistry.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter RedisAdapterCoreTest
php ./vendor/bin/phpunit --configuration=phpunit.xml
grep -c 'psalm-suppress' src/Adapter/Redis.php
echo "SENTINEL: reached end"
EOF
)
docker exec app-php83 bash -c "$task"
```

What each line has to prove, beyond "it ran":

- **`check-classes.php` is the only step that catches a load-time fatal.** A wrong type on a new
  property, or a `WeakMap` generic psalm cannot resolve, dies here and not in `php -l`.
- **The last `grep -c` is 5, and that is a measurement, not a target.** `AGENTS.md` records 12
  `@psalm-suppress` in `src/Adapter/` (`AbstractAdapter` 2, `Redis` 5, `MySql` 3, `Beanstalk` 1,
  `Beanstalk/Client` 1). Two new `$this?->logger->debug()` lines with the same call style can each
  need one, and a line that moved out of `hasWorkers()` can drop one. Re-measure and attribute
  every delta; do not add a suppression because psalm asked without reading what it asked.
- **Test counts before and after.** `RedisAdapterCoreTest` goes 39 → 48 (one replaced, ten added:
  the nine rows above plus `testTheRegistryForgetsAnAdapterThatIsNoLongerReferenced()`), and the
  full suite count must rise by exactly the same amount. A silent drop is a deleted test.
- **Grep in both directions.** After the change, `grep -rn 'hasWorkers' src/` must still name
  `AbstractAdapter`, `QueueProducer` and all three adapters — a new class in `Adapter/Redis/` that
  happened to reuse the name would not fail the suite, only this. And
  `grep -rn 'WorkerRegistry' src/ tests/` must name exactly `Redis.php` and the new test, or an
  adapter method is reaching around the design.
- **`phpunit.xml` sets `failOnSkipped="true"`.** Nothing in this plan touches the live-server test,
  so the count of skipped tests must not move either way.

## Traps

- **`__FUNCTION__` inside a closure is `{closure}`.** See 8.4 item 4. This codebase already has one
  method that had to be fixed for it (`connect()`) and one test that pins it. Two log lines that
  name `{closure}` are worse than no log lines.
- **A new class is not autoloadable until `composer dump-autoload` runs** — `classmap-authoritative`
  is on, and the failure mode is a load-time fatal, not a test failure. This is step 2 of the run
  order, not an afterthought.
- **Do not add a destructor.** §8.3.
- **Do not use `spl_object_id()` for the registry key.** It is reused after collection, so a
  dead worker and a new one would collide on the same id and a stale entry would answer for a
  worker that never existed. `WeakMap` has no such window.
- **Do not make the value hold the adapter.** A `WeakMap` value that references its key is
  self-referential; it happens to be collected on 8.3.33 (measured), and it is still the shape that
  makes the next reader's day harder. The value is a `string`.
- **`phpcbf` over `src tests` rewrites files this plan never opened.** Scope it to the three files
  above, and `git diff` anything it names before accepting the change.
- **The `hasWorkers()` answer is not "the queue is non-empty".** Do not "improve" it by reading
  `LLEN queues:{queue}`: a backlog with no worker is the case that must answer `false`, and that
  substitution is the bug Plan 7 §4.4 already rejected for `MySql`.

## If you take both plans

Plan 9 replaces the body of `hasWorkers()` with a Redis round trip. Two pieces of this plan survive
that substitution and should be landed first or kept:

- **`queueIdentity()`** — Plan 9's key is namespaced by the same `RedisConfig` fields, and the
  identity is what keeps a publisher from reading a registry that belongs to another server.
- **The `attempt()` wrapper and the `bindRead()`/`disconnect()` shape** — Plan 9 registers on the
  same two events.

`WorkerRegistry` itself does not survive: Plan 9's registry lives in Redis. If Plan 9 is likely,
do 8.2 and 8.3 anyway — they are the hooks — and take 8.4/8.5 with them so there is one test suite
describing what "a worker exists" means.
