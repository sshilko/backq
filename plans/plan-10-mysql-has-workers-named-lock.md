# Plan 10 — `MySql::hasWorkers()` from a MySQL named lock

> Status: **proposed, not started — and no longer the fix for anything.** The problem
> statement below is **historical**: `MySql::hasWorkers()` was a stub when this plan was written
> and is now answered from a companion `backq_workers` table by
> `plan-11-mysql-has-workers-lease-table.md`, which is **implemented**. So this plan is not
> needed to make the method work, and the reason it was worth writing — "the feature should work
> with no operational change at all" — has been spent by the other plan instead: it needed one
> `CREATE TABLE`.
>
> What is left of it is a genuine design **alternative, not a step**: if the `CREATE TABLE` is
> the part you cannot accept in your deployment, this is the plan that removes it. It is kept
> for that decision, and it should be read with Plan 11's "Corrections this plan needed" section,
> which is where the measured MySQL facts (error codes, the deprecated `VALUES()`, what a mocked
> suite cannot see) now live. Everything below is unverified by execution — the named lock was
> never measured, and the same warning Plan 11 earns the hard way applies here.
> Scope: `MySql::hasWorkers()` (`src/Adapter/MySql.php:270`), the two methods that take and drop
> the lock (`:302` `bindRead()`, `:296` `disconnect()`), the one that has to re-take it
> (`:334` `replaceDeadLink()`), one new optional field on `JobConfig`, ~~the MySQL service in
> `build/docker-compose.yaml`~~ (**done** — see 10.0), a new live test file, and the `README.md`
> feature table and `UPGRADING` bullet ~~that currently call the method a stub~~ (**done** — the
> method is no longer a stub, so this plan's doc work is now an *amendment* to Plan 11's entry,
> not a replacement of it).
> Companion plans: `plan-8-redis-has-workers-local-registry.md` and
> `plan-9-redis-has-workers-heartbeat.md` (the two Redis answers, both implemented), plus
> `plan-11-mysql-has-workers-lease-table.md` (implemented).
> **The alternative to this plan is `plan-11-mysql-has-workers-lease-table.md`**, which answers the
> same question from a companion table. **The two are alternatives, not steps.** Take this one when
> you want the feature to work with no operational change at all — no DDL, no new table, no config
> change in any existing deployment. Take Plan 11 when the answer has to be *inspectable* — when
> someone will ask "is anyone working on this queue?" and you want to be able to answer with
> `SELECT *`, and to have a row per worker you can read a host name and a pid off. Deciding between
> them is the whole decision; everything below is the cost of the server-side lock.

## The problem

`MySql::hasWorkers()` is a stub that answers `false` and logs "not supported"
(`src/Adapter/MySql.php:264-275`). The one caller in the library,
`AbstractPublisher::hasWorkers()` (`src/Publisher/AbstractPublisher.php:117-120`), hands the user
a permanently-false answer. `Beanstalk` answers it for real from the server (`statsTube` →
`current-watching`, `Beanstalk.php:111-143`) and so, as of Plan 9, does `Redis`. MySQL is the last
adapter still stubbed.

MySQL is structurally harder than Redis was, and the difference is worth stating before the design,
because it is what makes the lock the right answer rather than an obvious one:

- **There is no per-queue table.** `bindRead($queue)` and `bindWrite($queue)` **discard the queue
  argument entirely** and return `true` (`:302-311`); every statement is built from
  `JobConfig::$table`, one table for the whole server. So a query over the job table cannot answer
  "is a worker on *this* queue" — there is no "this queue" to filter on. The Redis registry gets
  per-queue scoping for free from its key name, `backq:workers:{queue}`; here the *name* is the only
  place queue identity can live.
- **There is no state at all.** `AbstractAdapter::isReady()` returns `true` unconditionally and
  `MySql` never holds a `ConnectionState` — the class has no state property and never assigns one.
  `MySql::preconditionFailed()` (`:318`) is therefore only reachable from an override, and
  `attempt()`'s precondition gate never fires for this adapter. Every `attempt()`-wrapped call in
  this file is a bare try/catch. Plan 10 relies on that: there is no bind state to consult, only
  the lock name this adapter remembers.
- **The link is not the adapter's.** It is injected in the constructor (`:79`) and belongs to the
  caller, who may swap it via `JobConfig::$connectionProvider` (`UPGRADING:472+`). `mysqli` cannot
  reconnect. A design whose liveness rests on a *connection* therefore has a lifecycle to honour
  that a Redis lease does not: the lock dies with the session, so every new session needs the lock
  again. That is 10.4, and it is the sharpest edge of this plan.

So the answer has to live somewhere both sides can see. MySQL already keeps exactly such a place:
**named locks**, which are server-side, per-session, and are released by the server when the
session goes away.

## The design

**One MySQL named lock per (scope, table, queue).** MySQL holds user locks in a flat, server-global
namespace; taking one is a statement, and the server drops it when the owning session ends. Three
statements, no TTL, no reap, no table:

| Who | Statement | Purpose |
|---|---|---|
| worker, on `bindRead($queue)` | `SELECT GET_LOCK("<name>", 0)` | announce |
| publisher, on `hasWorkers($queue)` | `SELECT IS_USED_LOCK("<name>") IS NOT NULL` | ask |
| worker, on `disconnect()` | `SELECT RELEASE_LOCK("<name>")` | release early |

**The caller does not own the link, and the server does own the liveness.** That is the whole
argument for this plan over Plan 11. A lease has to encode liveness as a *time*: a worker writes
"seen at T", a reader decides whether `now - T` is too old, and the two clocks have to be
comparable — which is why Plan 9 has to fetch `TIME` from Redis on both sides and take a `false`
negative whenever a worker is running a job longer than `workerTtl`. A named lock encodes liveness
as a *fact the server holds*: it exists or it does not, and "it does not" is the server's decision
about a session it can actually observe. There is no clock to disagree over, no TTL to set too low,
and no stale row to reap. `kill -9` a worker and the lock is gone when the server notices the dead
socket — seconds, not `workerTtl` seconds.

**The lock name is a pure function of config and queue.** No `DATABASE()` call, no extra round trip,
no failure mode:

```
backq-worker:<JobConfig::$workerScope>:<JobConfig::$table>:<queue>
```

`$workerScope` is a new `JobConfig` field, default `'backq'`, and it exists for one measured
reason: **MySQL's lock namespace is per server, not per schema.** Two BackQ deployments on one
server, both using the default table name, both publishing to `news`, would take the *same* lock —
and a publisher in one would be told there is a worker because the other one has one. That is a
false *positive*, the one direction this library cannot tolerate. `DATABASE()` inside the name would
fix it, but it needs a round trip and it can fail, and a failed lookup is a permanent silent
`false`. A field the operator sets is an explicit, documented, testable answer. The README says:
*if two BackQ deployments share a MySQL server, give them different `workerScope` values.*

The name is also length-capped: the manual documents a maximum for `GET_LOCK`, and truncation is not
a failure, it is a **collision**. A name over the cap is replaced wholesale by
`'backq-worker:' . md5($name)` rather than cut. The cap is measured in 10.1; until it is, assume
64 characters. `LOCK_NAME_MAX` is a constant for the same reason `Redis::WORKERS_KEY` is: a
truncated name is the silent kind of bug.

**The lock is never load-bearing.** Taking, re-taking and releasing are each `attempt()`-wrapped and
the answer is discarded, except that a *refusal* is logged at `debug` with the consequence spelled
out. A worker that could not take its lock must still work; it is simply a worker `hasWorkers()`
does not report, which is the library's existing bias toward `false` (see below). `bindRead()`
therefore still returns `true` unconditionally, exactly as it does today. This is a hard rule with
tests behind it (10.6).

**No renew, and no write in `pickTask()`.** This is the cost line that has no counterpart in
either Redis plan. Plan 9 renews a lease in `pickTask()` every `ttl/3` seconds; Plan 11 renews a
row. Plan 10 writes nothing at all on the worker's hot path — the lock is taken once in `start()`
and released once in `finish()`. `pickTask()` (`:92-127`) is **not modified at all**, which also
means the `begin_transaction()` … `commit()` window and the `usleep($pickMissSleep)` outside it
stay exactly as they are.

**A MySQL 5.7 floor, which is why 10.1 measures it.** Before 5.7 a session could hold one named
lock, and taking a second *released the first*. Two adapters sharing one caller-owned link — a
worker and a publisher in the same process, which this adapter permits because `bindRead` and
`bindWrite` are both no-ops — would then steal each other's locks, and the worker's lease would
vanish and reappear. 5.7 allows several per session. If the deployment floor is below 5.7, take
Plan 11. Note that even on 8.0+ a *second* `GET_LOCK` for the same name by the same session
succeeds and increments a counter that needs a matching number of `RELEASE_LOCK` calls, so the
`$locked` guard in 10.3 is not tidiness — it is what keeps the release count balanced. Also
measured in 10.1: named locks are session-scoped and are **not** transactional, so the
`commit()`/`rollback()` in `pickTask()` neither takes nor drops them. That is the behaviour this
plan needs, and it is a claim from the manual, not from this host.

### What the answer means, stated precisely

`hasWorkers()` answers **"a MySQL session is holding this queue's worker lock right now"**. Because
the lock is session-scoped, that means:

- a worker that is **running a job** holds the lock and is reported. Correct: it is a worker, and it
  will come back for more.
- a worker that is **idle between picks** holds the lock and is reported. This is the case that
  matters, and it is the one Plan 9 cannot always get: there, a worker running a job longer than
  `workerTtl` is not reported. Here the lock is not a clock, so a job of any length is fine. **Plan
  10 has no `workerTtl` and no false negative of that kind** — the single strongest argument for it.
- a worker that was **`kill -9`'d** is not reported, as soon as the server notices the socket is
  dead. The delay is the server's, not a TTL: bounded by `net_write_timeout`/`wait_timeout` in
  pathological cases, seconds in practice. Measure it in 10.1.
- a worker whose **link was swapped** by `replaceDeadLink()` is not reported until 10.4 re-takes
  the lock. There is a window between the close and the re-take, and it is one statement wide.
- a worker whose **lock could not be taken** because another session already holds it is not
  reported. On 5.7+ two workers on the same queue both hold it (locks are re-entrant *across*
  sessions in the sense that several sessions may hold one name — each `GET_LOCK` succeeds), so this
  is not a normal state; it is an error, logged at `debug`.

The bias is the same as every other answer in this library: the only way to answer `true` is to find
a holder, and every other outcome is `false`. A publisher that gets `false` publishes anyway, which
is the safe direction.

## Measured, not assumed

Measured in `app-php83` (PHP 8.3.33, mysqlnd 8.3.33, `zend.assertions=-1` so `assert()` in `src/`
is compiled out). **There is no MySQL server anywhere in the test environment** — see the table.

| Question | Answer | How |
|---|---|---|
| Does the `mysqli` extension load? | yes, `mysqlnd 8.3.33` | `mysqli_get_client_info()` |
| Does `mysqli` have `get_lock` / `release_lock` / `is_used_lock` methods? | **no, none of the three** | `ReflectionClass('mysqli')->hasMethod()` — so every lock operation is a `SELECT` |
| Is there a `mysqli` service in `build/docker-compose.yaml`? | **no** — only `redis` | grep for `container_name` |
| Is a mysqld reachable from the container? | no: `127.0.0.1:3306`, `mysql:3306`, `backq-mysql:3306` all refused/unresolved | `fsockopen` |
| How does the MySQL test layer get a link today? | **`createMock(mysqli::class)`** — 41 tests, no server | `MySqlAdapterTest::db()` |
| What does `mysqli::query()` return? | **no declared return type** — reads as `mixed` | reflection; hence `select()`'s `instanceof mysqli_result` narrowing (`:417`) |
| Is `mysqli_result::num_rows` a method? | **no** — it is a property | reflection, `hasMethod()` = 0 |
| Is `AbstractAdapter::isReady()` ever false for `MySql`? | no, it returns `true` unconditionally and `MySql` has no state | read `AbstractAdapter` |
| Does `MySql` use the server clock already? | yes: three `NOW()` writes | `:109`, `:177`, `:193`, `UPGRADING:466-471` |
| Can `JobConfig` take new fields? | yes, `final readonly` with 7 fields appended in order; `JobConfigTest` constructs positionally at `:34` and `:46`, so **append last** | read the test |

The third row is the one that shapes this plan more than any other: **this repository has never run
a single MySQL statement.** Every MySQL test in it asserts the *string* the adapter would have sent
to a mock, which is a real and useful thing to test, and is not evidence that the statement is
valid SQL, that the function exists, that it returns what the manual says, or that the lock behaves
as advertised. Every claim in the next section is therefore deferred to 10.1, and **10.1 comes
before 10.2 in the numbering because it is the gate for the rest of the plan.**

## Out of scope (considered and deliberately excluded)

- **Inferring the answer from job rows.** `sync = 'LOCK'` rows are jobs in progress: a worker
  exists, but it is *busy*, which is the opposite of what the question asks. `sync = 'WAIT'` is a
  backlog, not a worker. And an idle worker — one sitting in the `usleep($pickMissSleep)` at `:124`
  — leaves no row whatsoever. No query over `backq_jobs` answers "is a worker available".
  `UPGRADING:461-465` currently justifies the stub with the clause "the query it would need is the
  same query that already knows the answer". **That clause is false**, and taking this plan makes
  the sentence a historical record of a decision that was later reversed — so 10.7 amends it rather
  than leaving it.
- **`information_schema.PROCESSLIST`.** The MySQL twin of the Redis `CLIENT LIST` that Plan 9
  rejected. It needs the `PROCESS` privilege, it is `O(sessions)` on a shared server, it cannot
  distinguish a worker from any other sleeping client, and it cannot see a *queue*. A monitoring
  tool, not an availability check.
- **A heartbeat row inside `backq_jobs` itself.** No DDL, so it looks free. It is not:
  `putTask()` writes `UPDATE … WHERE id = "<caller-supplied>"` (`:144-218`), and job ids are the
  caller's to choose, so a job whose id collides with a worker's heartbeat row rewrites that
  worker's lease. It also pollutes a table users read directly. This is the collision that makes
  Plan 11 need a *separate* table rather than a column.
- **A tri-state answer.** `bool` is the 5.x contract (`UPGRADING:58-63`). "unknown" would need a
  new signature on two interfaces, three adapters, and every third-party adapter.
- **A count.** `bool` is the return type (`AbstractAdapter.php:149`); a count is thrown away.
- **Reporting on `connectionProvider` failure.** If a worker cannot get a new link it is dead
  (`replaceDeadLink()` returns `false` and `ping()` reports the failure). Whether it still holds a
  lock is the link's business, not this plan's.
- **Heartbeats from `AbstractWorker`.** The adapter already sees the bind and the disconnect, and
  the adapter is what a user may replace. Keeping it in the adapter means a hand-written adapter
  does not silently lose the feature.

## How to execute this plan

Same two-phase ritual as Plans 1–9: **Phase A** writes the tests against the current stub (RED),
**Phase B** makes them pass. `JobConfig` gains a field, not a class, so `composer dump-autoload` is
not required — run it anyway if 10.6 adds a test class, and before the suite regardless.

Phase A's live half is the half that cannot be faked, and 10.0 is what makes it possible. Note the
consequence that comes with it: `phpunit.xml` sets `failOnSkipped="true"`, so a skipped live test
is a **failed run**. Adding a MySQL service to compose is what makes the container mandatory for a
green suite; a host without it now fails for two services instead of one. That is the right outcome
(AGENTS.md already says all test work happens in `app-php83`) but it must be written in the
README's contributing notes so nobody reads it as a regression.

---

## 10.0 A MySQL service in the test environment (shared with Plan 11)

> **Already done by Plan 11, and the block is worth reading for what it cost.** `mysql80` runs
> MySQL 8.0.46, `app-php83` waits for it with `condition: service_healthy`, and
> `tests/Adapter/MySqlLiveTest.php` (Plan 11's) is the first file in this repository to execute a
> MySQL statement. It needed a real fix, not just an addition: the `x-mysql` anchor had been placed
> *after* the service that used it, so `docker compose config` failed and nothing started at all.
> The environment this section asks for exists; the measurements below do not.

The single largest cost in this plan, and the reason it starts at 10.0 rather than at the code.
`build/docker-compose.yaml` currently has `app-php83`, `redis` and `composer-cache`. Add a service
alongside `redis`, pinned and named the way the other services are:

```yaml
  mysql:
    image: mysql:8.0
    container_name: backq-mysql
    command: --default-authentication-plugin=mysql_native_password
    environment:
      MYSQL_ROOT_PASSWORD: backq
      MYSQL_DATABASE: backq_test
    ports:
      - '13306:3306'
    healthcheck:
      test: ['CMD', 'mysqladmin', 'ping', '-h', '127.0.0.1', '-pbackq']
      interval: 5s
      timeout: 3s
      retries: 30
```

Pin the version rather than using `latest`: the lock semantics this plan depends on differ between
8.0 and 8.4, and a plan whose liveness argument is "the server drops the lock" needs the server
version in the diff. `app-php83` needs `depends_on: mysql: condition: service_healthy`, and the
`extra_hosts` / port conventions already used for `redis` (`BACKQ_REDIS_PORT`) get a
`BACKQ_MYSQL_PORT` twin so a host-side run can reach `127.0.0.1:13306`.

`tests/Adapter/MySql/Schema.php` gets the `CREATE TABLE backq_jobs` statement the existing MySql
tests assume but never run, as a fixture both this plan's live test and Plan 11's reuse.

## 10.1 Prove the server-side claims before writing the adapter

Everything in the design above that concerns MySQL's behaviour is from the manual. This item turns
those into measurements, and **its output is the input to 10.2's `LOCK_NAME_MAX`**. Write it as a
script in `tmp/` (gitignored) first, then keep the assertable half as the live test in 10.6.

| # | Claim the plan rests on | How to measure it | If it is false |
|---|---|---|---|
| 1 | `GET_LOCK`/`IS_USED_LOCK`/`RELEASE_LOCK` exist and are callable | `SELECT GET_LOCK('probe', 0)` | stop; the plan has no mechanism |
| 2 | The maximum lock-name length | a name of 200 chars, then of 64, then of 63 | set `LOCK_NAME_MAX` to what it is; if truncation is silent, the `md5` fallback in 10.2 is mandatory, not optional |
| 3 | `IS_USED_LOCK` returns the holder's connection id, `NULL` when free | take it from one link, ask from a second | the `IS NOT NULL` test in 10.5 is wrong |
| 4 | A lock is released when the session ends | take it, then close the link without `RELEASE_LOCK`, then ask from another link | the whole liveness argument is wrong |
| 5 | A lock survives `kill -9` of the holder | take it, `kill -9` the holder process, poll `IS_USED_LOCK` until `NULL`, record the delay | state the real bound in the README instead of "as soon as the server notices" |
| 6 | Named locks are not transactional | take a lock, `BEGIN`, `ROLLBACK`, then ask | if the lock is dropped, the plan must take it outside a transaction and re-take per pick — a redesign |
| 7 | A second `GET_LOCK` on the same session for the same name succeeds and needs a matching `RELEASE_LOCK` | take twice, release once, ask from a second link | the `$locked` guard in 10.3 is what keeps this correct either way; record the answer |
| 8 | Neither function needs a privilege | connect as a user with only `SELECT` on the schema | if `GET_LOCK` needs more, that is a documented deployment requirement |
| 9 | Minimum server version for several locks per session | 5.6 vs 5.7 | below 5.7 the plan is a non-starter; take Plan 11 |

Row 5 is the one to spend time on: it is the difference between "liveness is exact" and "liveness
is exact up to the server's dead-connection detection", and the README must say which.

## 10.2 The lock name

Two new constants and a private method on `MySql`, plus one field on `JobConfig`:

```php
// src/Adapter/MySql.php
/**
 * The prefix every named lock carries, so one shows up recognisable in a server-side
 * lock listing
 */
private const LOCK_PREFIX = 'backq-worker:';

/**
 * The longest name this plan hands to GET_LOCK, measured in 10.1
 */
private const LOCK_NAME_MAX = 64;

/**
 * The name of the worker lock for a queue
 *
 * A pure function of the config and the queue: no DATABASE() call, so there is no
 * round trip to fail and no lookup whose failure is permanent. The scope segment is
 * what keeps two deployments on one server apart, because MySQL's lock namespace is
 * per server rather than per schema.
 *
 * A name over the measured maximum is replaced wholesale, never cut: truncation is
 * silent and a collision is a false positive, which is the one direction this
 * library cannot tolerate.
 */
private function lockName(string $queue): string
{
    $name = self::LOCK_PREFIX . $this->config->workerScope . ':' . $this->config->table . ':' . $queue;

    return strlen($name) <= self::LOCK_NAME_MAX ? $name : self::LOCK_PREFIX . md5($name);
}
```

```php
// src/Adapter/MySql/JobConfig.php — appended last, the test constructs positionally
/**
 * @param string $workerScope keeps two BackQ deployments on one MySQL server apart in
 *        the server-global named-lock namespace. Change it when two deployments share
 *        a server and use the same table name.
 */
public string $workerScope = 'backq',
```

`strlen()` is bytes, which is the right unit for a name that may hold a multibyte queue name, and
the `md5` fallback is what makes the cap a non-issue for long queue names. A queue name is
caller-supplied and goes through `escape()` at every use site, exactly like a job id does at `:110`.

## 10.3 `bindRead()` takes the lock, `bindWrite()` does not

`bindRead()` is the only place a lock is ever taken, which is what keeps a publisher-only adapter
from finding itself — the same structural guarantee Plan 9 relies on, and the reason no
self-exclusion query is needed.

```php
/**
 * The name of the worker lock this adapter holds, null when it holds none
 */
private ?string $heldLock = null;

#[Override]
public function bindRead(string $queue): bool
{
    $this->attempt(__FUNCTION__, fn () => $this->takeLock($queue));

    /**
     * A worker that could not take its lock is still a worker. It is a worker
     * hasWorkers() does not report, which is the bias the whole library has.
     */
    return true;
}

#[Override]
public function bindWrite(string $queue): bool
{
    return true;
}

/**
 * Take the worker lock for a queue, once
 *
 * Guarded by $heldLock rather than merely by intent: on MySQL 8 a second GET_LOCK
 * for the same name by the same session succeeds and increments a counter that wants
 * a matching number of RELEASE_LOCK calls (measured in 10.1 row 7). A caller that
 * binds twice is answered with one lock and one release.
 *
 * @return bool whether this adapter holds the lock afterwards
 */
private function takeLock(string $queue): bool
{
    $name = $this->lockName($queue);
    if ($name === $this->heldLock) {
        return true;
    }

    $rows = $this->select('SELECT GET_LOCK("' . $this->escape($name) . '", 0) AS got');
    if (1 !== (int) ($rows[0]['got'] ?? 0)) {
        $this?->logger->debug(
            __FUNCTION__ . ': the worker lock for this queue is held by another session, '
            . 'hasWorkers() will not report this worker'
        );

        return false;
    }

    $this->heldLock = $name;

    return true;
}
```

Three details that are load-bearing:

- **`GET_LOCK(name, 0)`, never a positive timeout.** A non-zero timeout would make `bindRead()`
  block, and `bindRead()` is called from `AbstractWorker::start()` (`:193`) on a worker's startup
  path. Zero means "take it or tell me now".
- **A `0` return is a `debug`, not an `error`.** It is not a failure of the library; it is a
  statement about liveness, and logging it at `error` once per worker start is noise.
- **`$heldLock` is the authority, not `$this->config->workerScope`.** Two adapters on one link with
  two different scopes must each hold their own lock, and the name is what tells them apart.

## 10.4 `disconnect()` releases it, `replaceDeadLink()` re-takes it

This is the part Redis's lease did not have to think about, and skipping it is the bug that makes
the feature work until the first link swap and then stop working forever.

```php
/**
 * The connection is owned by the caller, it outlives the adapter, and the worker
 * lock is owned by the server and dies with the session
 */
#[Override]
public function disconnect(): bool
{
    $name = $this->heldLock;
    if (null === $name) {
        return true;
    }

    $this->attempt(__FUNCTION(), function () use ($name): void {
        $this->select('SELECT RELEASE_LOCK("' . $this->escape($name) . '")');
    });

    /**
     * Cleared even when the release failed: the statement above is a SELECT, so a
     * failure here is a broken link, and a broken link means the session is gone and
     * the server has already dropped the lock. Holding the name would stop a later
     * bindRead() from re-taking a lock this session no longer has.
     */
    $this->heldLock = null;

    return true;
}
```

And inside `replaceDeadLink()` (`:334-356`), immediately after `$this->closeLink($dead);` at `:351`:

```php
$dead = $this->db;
$this->db = $replacement;
$this->closeLink($dead);

/**
 * The lock belonged to the dead link's session, which closeLink() just ended, so
 * this adapter is no longer holding it. Re-take it before the worker goes back to
 * work, and only while it believed it held one: a publisher's link comes and goes
 * too and has nothing to re-take.
 */
if (null !== $this->heldLock) {
    $this->attempt(__FUNCTION__, fn () => $this->takeLockForHeldName());
}
```

`takeLockForHeldName()` is `takeLock()` split so the re-take re-uses the *held* name rather than
re-deriving it from a queue argument that is no longer in scope. If the re-take fails,
`takeLock()` returns `false` and `$heldLock` must be cleared, so the next pick does not believe in
a lock it does not have — which means the guard reads the name into a local first, exactly as
`disconnect()` does above. A worker that lost its lock stays a worker; it is simply not reported.

The bounded cost of the leak in the other direction: `disconnect()` clearing `$heldLock` after a
*failed* release means a lock the session still holds, in the rare case where the statement failed
for a reason other than a dead link, survives until that session ends. `disconnect()` is called from
`AbstractWorker::finish()` (`:389`), i.e. at the end of `run()`, so the session is normally about
to close anyway.

## 10.5 `hasWorkers()` reads the lock

```php
/**
 * A table queue has no worker row, so a named lock is where the answer comes from.
 * The lock is per session, so this answers "a worker is holding this queue now" and
 * a worker blocked in usleep(pickMissSleep) is one.
 */
#[Override]
public function hasWorkers(string $queue): bool
{
    return $this->attempt(__FUNCTION(), function () use ($queue): bool {
        $name = $this->lockName($queue);
        $rows = $this->select('SELECT IS_USED_LOCK("' . $this->escape($name) . '") IS NOT NULL AS used');

        return 1 === (int) ($rows[0]['used'] ?? 0);
    });
}
```

**`select()`, never `write()`.** `write()` (`:434-442`) frees a result set and returns `0` for any
statement that produced one, so building `hasWorkers()` on it would answer "no workers" on every
call, forever, with a green suite behind it. This is the MySQL twin of the `getRedis()` trap Plan 9
hit, and it is the one to write down twice. See Traps.

`IS_USED_LOCK(…) IS NOT NULL` rather than `IS_USED_LOCK(…)` directly, so the row answers a `1`/`0`
the shape check reads. Comparison happens server-side, so the connection id's string-vs-int type
never enters PHP. The alias `used` is required — `select()` returns `MYSQLI_ASSOC` maps keyed by
column name (`:421`), and an unaliased expression column arrives under a driver-generated key that
is not worth depending on.

`attempt()` is the right wrapper (`AbstractAdapter.php:42` lists `hasWorkers()` under it): log
`error`, return `false`. A statement that throws leaves the answer at "no workers", which is the
safe direction, and the log carries the exception for the handler to match on.

## 10.6 Tests

**Offline, in `tests/Adapter/MySqlAdapterTest.php`** — 41 tests today, all mock-based. **Fourteen added**, in the
thirteen items below (item 12 is two tests), none of which needs a server. They assert the *strings*, which is what this layer is good at and
all it has ever been good at:

1. `testBindReadTakesTheNamedLockForTheQueue` — exactly one statement, and it is
   `SELECT GET_LOCK("backq-worker:backq:backq_jobs:news", 0) AS got`. The `WORKERS_KEY`-style
   constant analogue: the name is a contract, so assert it literally.
2. `testBindReadTakesTheLockOnlyOnce` — two `bindRead()` calls, one `GET_LOCK` statement. This is
   the MySQL 8 counter trap from 10.1 row 7, and it is why `$heldLock` is a guard and not a comment.
3. `testBindWriteTakesNoLock` — a publisher-only bind issues no statement at all. The structural
   reason a publisher cannot find itself.
4. `testBindReadStillReturnsTrueWhenTheLockIsHeldElsewhere` — answer `[["got" => 0]]`, assert
   `true` and a `debug` log with `assertLogged()`.
5. `testBindReadStillReturnsTrueWhenTheStatementThrows` — assert `true` and an `error` log. The
   never-load-bearing rule, on the method that most tempts a `return false`.
6. `testHasWorkersIsTrueWhenTheNameIsLocked` — `[["used" => 1]]` → `true`.
7. `testHasWorkersIsFalseWhenNoSessionHoldsTheName` — `[["used" => 0]]` → `false`. This **replaces**
   `testHasWorkersReportsNotSupported()` and is the only guard against a "always true" bug.
8. `testHasWorkersIsFalseWhenTheStatementThrows` — `false` plus an `error` log.
9. `testHasWorkersReadsTheNameForItsOwnQueue` — two different `$queue` arguments produce two
   different lock names, so `hasWorkers('news')` never reads `hasWorkers('mail')`'s answer.
10. `testTheLockNameIsHashedRatherThanTruncatedWhenItIsTooLong` — a 200-character queue name yields
    `backq-worker:` plus 32 hex characters, and no fragment of the tail.
11. `testTheLockNameSeparatesTwoWorkerScopes` — two `JobConfig`s differing only in `workerScope`
    produce different names. This is the deployment-collision case from the design section.
12. `testDisconnectReleasesTheLockItTook` and `testDisconnectTakesNoLockWhenBindReadNeverRan` — the
    release is issued exactly once after a bind, and never at all without one. The publisher's
    `disconnect()` must not release somebody else's lock.
13. `testReplaceDeadLinkTakesTheLockAgain` — bind, make `ping()` report a dead link with a
    `connectionProvider`, and assert a second `GET_LOCK` on the replacement link. **This is the test
    that 10.4 exists for**; without it the feature works until the first link swap and then reports
    `false` for the rest of the worker's life.

Plus `tests/Adapter/MySql/JobConfigTest.php`: `testTheWorkerScopeDefaultsAndCanBeOverridden` and
`testTheDefaultWorkerScopeSurvivesAnEmptyConfig`. The nine existing tests construct `JobConfig`
positionally at `:34` and `:46`, so appending the field last is what keeps them passing — and that
is worth an assertion, because the next person to add a field in the middle will break them.

**Live, in a new `tests/Adapter/MySqlLiveTest.php`.** This file is the whole reason 10.0 exists, and
it is the first MySQL test in this repository to execute SQL. It mirrors `RedisAdapterTest`'s
reachability guard, and it turns rows 1–9 of the 10.1 table into assertions:

- `testTheWorkerLockIsVisibleToAnotherSession` and `testTheLockIsReleasedWhenTheSessionEnds` — the
  liveness claim, measured rather than cited.
- `testTheLockSurvivesCommitAndRollback` — the non-transactional claim, which is the one 10.4
  depends on.
- `testANameAtTheMeasuredMaximumIsNotTruncated` — pins `LOCK_NAME_MAX` against the server, so a
  version bump that lowers the cap fails here instead of colliding in production.
- `testTheLockDisappearsWhenTheHolderIsKilled` — spawns a child process that takes the lock, kills
  it, polls for release with a bounded wait, and asserts the delay. The one test that measures the
  plan's headline number.
- `testAWorkerBoundReadIsReportedAndAPublisherIsNot` — end to end: a real worker adapter on one
  link, a publisher adapter on another, `bindRead()` then `hasWorkers()` → `true`, and `false` for
  a queue nobody bound.

Mark it `#[Group('integration')]`-free but guarded the same way `RedisAdapterTest` is, so
`failOnSkipped="true"` produces the correct failure outside the container.

## 10.7 Docs

- `README.md:164-191`: the `MySQL` row's `hasWorkers` cell goes `*` → `✓`, and the `*` legend
  keeps `setWorkTimeout()` (still a worker concern, still shared through the table). Replace the
  `MySql::hasWorkers() reports no workers without checking` bullet with a paragraph in the shape of
  the Redis one: the answer comes from a server-side named lock, so it is exact, it needs no TTL and
  no table, and **two deployments on one server need different `workerScope` values**.
- `UPGRADING`: amend the `:461-465` bullet — the method is no longer a stub, and the clause "the
  query it would need is the same query that already knows the answer" is **deleted**, because it is
  false and the reader now needs to know what actually answers it. Add the `workerScope` field to
  the `JobConfig` constructor listing alongside the existing signature note, and the 5.7 floor.
- `AGENTS.md`: the `MySql` psalm-suppression count, the new `write()`-on-a-`SELECT` trap, and the
  "two services or the suite cannot be green" consequence of 10.0. Re-measure the counts, do not
  predict them.
- `plans/`: this file's status block, and Plan 11's cross-reference.

## Verification

```bash
task=$(cat <<'EOF'
cd /app || exit 1
php -l src/Adapter/MySql.php
php -l src/Adapter/MySql/JobConfig.php
php build/check-classes.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s \
  src/Adapter/MySql.php src/Adapter/MySql/JobConfig.php \
  tests/Adapter/MySqlAdapterTest.php tests/Adapter/MySql/JobConfigTest.php \
  tests/Adapter/MySqlLiveTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon \
  src/Adapter/MySql.php src/Adapter/MySql/JobConfig.php
php vendor/bin/psalm.phar --config build/psalm.xml --memory-limit=-1 --no-diff --show-info=true \
  src/Adapter/MySql.php src/Adapter/MySql/JobConfig.php
php -d memory_limit=-1 vendor/bin/phpunit --configuration=phpunit.xml --filter 'MySqlAdapterTest|MySqlLiveTest'
php -d memory_limit=-1 vendor/bin/phpunit --configuration=phpunit.xml
grep -c 'psalm-suppress' src/Adapter/MySql.php
grep -rn 'psalm-suppress' src/Adapter/ | wc -l
echo "SENTINEL: reached end"
EOF
)
docker exec app-php83 bash -c "$task"
```

Beyond the sentinel:

- **Baseline the counts before touching anything.** Measured for Plan 9: 385 tests / 981 assertions
  before, 402 / 1040 after, and the whole delta attributed. The MySQL layer starts at
  `MySqlAdapterTest` 41 tests / 109 assertions and `JobConfigTest` 9. Every new test is counted on
  the way in; a drop means a deleted test.
- **`MySqlLiveTest` must not skip.** `failOnSkipped="true"` turns a skip into a failure, which is
  the right outcome on a host with no MySQL. In the container the count of skips must be zero — and
  "zero" is a measurement, not an assumption: print the skip count, do not infer it from a green
  line.
- **The green mock suite is not evidence.** `MySqlAdapterTest` mocks `mysqli`, so all fourteen
  offline tests above pass whether `GET_LOCK` exists, is spelled correctly, or returns `1`. The live
  file is the only thing in this plan that can be wrong about MySQL. Say that in the PR.
- **No table left behind.** After the full suite, `SELECT * FROM backq_workers`-shaped fixtures and
  the schema fixture must be dropped by the live test's teardown, and a leaked lock must be
  released. A leaked `backq-worker:*` lock on a long-lived connection makes a later `hasWorkers()`
  test pass for the wrong reason.
- **Re-measure the psalm-suppression counts.** `src/Adapter/MySql.php` has 3 today (`:58`, `:59`,
  `:331`) and `src/Adapter/` has 14. Two new methods that log through `$this?->logger` will each
  want their own `TypeDoesNotContainNull` / `PossiblyNullPropertyAssignment` pair, so expect 3 → 5
  in the file and 14 → 16 across the directory. **Measure and attribute each one; never add a
  suppression to silence a question without reading it.** If a count does not move, that is also a
  finding worth a sentence — it means psalm did not consider the value uncertain.

## Traps

- **`write()` returns `0` for anything that produced a result set** (`:434-442`). `GET_LOCK`,
  `IS_USED_LOCK` and `RELEASE_LOCK` are all `SELECT`s, so all three go through `select()`. Built on
  `write()`, `hasWorkers()` answers `false` forever and the mock suite is green. This is the MySQL
  version of the `getRedis()` trap that Plan 9 documented: **a failure mode the mocked layer cannot
  reach.**
- **`mysqli` has no `get_lock()` method.** Measured. It is `SELECT GET_LOCK(...)`, and
  `mysqli::query()` has **no declared return type**, so `select()`'s `instanceof mysqli_result`
  narrowing (`:417`) is what stands between a result set and a `TypeError`. Do not "simplify" the
  helper into a direct `$this->db->query()` call.
- **`mysqli_result::num_rows` is a property, not a method.** Measured. The plans do not use it
  (`COUNT(*)` and an `IS NOT NULL` alias are both driver-independent), but a reader who reaches for
  `$result->num_rows()` gets an `Error`.
- **`GET_LOCK` with a non-zero timeout blocks.** `bindRead()` runs on the worker's startup path
  (`AbstractWorker::start()`: `:193`). The timeout argument is `0` and must stay `0`.
- **A second `GET_LOCK` on one session for one name succeeds and must be released twice.** The
  `$heldLock` guard is what prevents the extra take. Measured in 10.1 row 7; treat the answer as
  load-bearing either way.
- **The lock namespace is per server, not per schema.** Two default-config deployments on one server
  collide. `workerScope` is the fix, and the README bullet is what stops the next deployment from
  discovering it in production.
- **Truncating a lock name collides silently.** No error, no warning — just two queues sharing a
  lock and a false positive. The cap is measured, and the fallback hashes.
- **`MySql` has no `ConnectionState` and `isReady()` is always `true`.** So `attempt()`'s
  precondition gate never fires for this adapter, `preconditionFailed()` (`:318`) is unreachable, and
  there is no bind state to ask whether this adapter is a worker. `$heldLock` is the only record of
  that, and it is the only thing 10.5 could have used to exclude a publisher's own process.
- **`disconnect()` on a publisher must not release anything.** With no `$heldLock` the method returns
  immediately; the test at 10.6 item 12 is what keeps a later refactor from dropping that guard.
- **`RELEASE_LOCK` on a lock this session does not hold returns `0`**, not an error. Harmless here,
  but it means "the release did nothing" is indistinguishable from "it was never taken" — do not
  read it as a failure.
- **Do not put the heartbeat in `backq_jobs`.** `putTask()`'s `UPDATE … WHERE id = "<caller's id>"`
  (`:144-218`) can rewrite a row that is not its own. The reason Plan 11 needs a separate table is
  this line.
- **`failOnSkipped="true"` means the container is now mandatory.** Two services, not one. That is
  the honest consequence of 10.0, not a regression.

## If you take both plans

Plan 10 and Plan 11 answer the same method, and combining them is one line:

```php
return $this->lockSays($queue) || $this->tableSays($queue);
```

Worth doing only in that order, for the same reason Plan 9's section says: the lock is exact, costs
no write, and needs nothing installed, so it short-circuits and the table is the fallback for
operators who want visibility. Never the reverse — `hasWorkers()` must not write a row when the
answer is already available. What survives unchanged from Plan 11 in that case: `JobConfig::$table`
and `$workerScope` do double duty, `bindRead()`/`disconnect()` keep their hooks, and every write
stays `attempt()`-wrapped with the answer discarded. What does not: the renew in `pickTask()`,
which nothing needs once the lock answers, and `workerTtl`, which the lock has no use for.
