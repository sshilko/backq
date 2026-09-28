# Plan 11 — `MySql::hasWorkers()` from a companion worker table

> Status: **implemented.** The design survived; fourteen things in the sketches and the text around
> them did not. They are in "Corrections this plan needed" at the end, and that section is the
> part to read before porting any of this to another adapter — seven of the fourteen are things a
> mocked test suite cannot catch at all.
> Scope: `MySql::hasWorkers()` (`src/Adapter/MySql.php:270`), the three methods that write, renew and
> drop the lease (`:302` `bindRead()`, `:92` `pickTask()`, `:296` `disconnect()`), two new optional
> fields on `JobConfig`, the MySQL service in `build/docker-compose.yaml`, a new live test file, and
> the `README.md` feature table and `UPGRADING` bullet that called the method a stub.
> Companion plans: `plan-8-redis-has-workers-local-registry.md` and
> `plan-9-redis-has-workers-heartbeat.md` (the two Redis answers, both implemented), plus
> `plan-10-mysql-has-workers-named-lock.md`, which is **still proposed and not started** and
> remains a live alternative rather than a next step.
> **The alternative to this plan is `plan-10-mysql-has-workers-named-lock.md`**, which answers the
> same question from a MySQL named lock. **The two are alternatives, not steps.** Take this one when
> the answer has to be *inspectable*: when someone will ask "is anyone working on this queue?" and
> you want to answer with a `SELECT`, when a worker row should be able to carry a host name and a
> pid, and when adding one `CREATE TABLE` to a deployment is an acceptable cost. Take Plan 10 when
> you want the feature to work with no operational change at all — no DDL, no new table, no config
> change in any existing deployment. Deciding between them is the whole decision; everything below
> is the cost of the table.

## The problem

`MySql::hasWorkers()` is a stub that answers `false` and logs "not supported"
(`src/Adapter/MySql.php:264-275`). The one caller in the library,
`AbstractPublisher::hasWorkers()` (`src/Publisher/AbstractPublisher.php:117-120`), hands the user
a permanently-false answer. `Beanstalk` answers it for real from the server (`statsTube` →
`current-watching`, `Beanstalk.php:111-143`) and so, as of Plan 9, does `Redis`. MySQL is the last
adapter still stubbed.

MySQL is structurally harder than Redis was, and the difference is what decides the shape of this
plan:

- **There is no per-queue table.** `bindRead($queue)` and `bindWrite($queue)` **discard the queue
  argument entirely** and return `true` (`:302-311`); every statement is built from
  `JobConfig::$table`, one table for the whole server. So a query over the job table cannot answer
  "is a worker on *this* queue" — there is no "this queue" to filter on. Any registry for this
  adapter needs a **queue column of its own**, which is a schema change, not a config change. This
  is the single structural reason this plan is more expensive than Plan 9 was for Redis, where the
  queue was already in the key name.
- **There is no place to put a worker row.** The job table is the only table, and a heartbeat row in
  it is not free: `putTask()` writes `UPDATE … WHERE id = "<caller-supplied>"` (`:144-218`) and job
  ids are the caller's to choose, so a job whose id collides with a worker's row **rewrites that
  worker's lease**. The registry has to be a *separate* table. A column on `backq_jobs` would work
  and is rejected here for the same reason plus a second one: a nullable `worker_token` on the job
  table would have to be excluded from `pickTask()`'s `WHERE sync = 'WAIT'` read, so the two
  features would be coupled through a query whose job is to be fast.
- **There is no state at all.** `AbstractAdapter::isReady()` returns `true` unconditionally and
  `MySql` never holds a `ConnectionState`. There is no bind state to consult, so this plan's
  "is this adapter a worker" record is its own `private ?string $workerToken` — the same shape Plan 9
  used in `src/Adapter/Redis.php`.
- **The link is not the adapter's** (`:79`, `UPGRADING:472+`), and `mysqli` cannot reconnect. Unlike
  Plan 10, this design does not care: the lease is in a table, not in a session, so a link swap is
  invisible to it. That is a genuine advantage of the table over the lock, and it is worth naming
  because it is the reverse of the one thing Plan 10 gets to skip.

So: a companion table, one row per worker, an expiry, and the same lease discipline Plan 9 already
ships for Redis. This is Laravel Horizon's shape again (`WorkerRepository`, a per-supervisor record
with a heartbeat), applied to a table instead of a sorted set.

## The design

**A second table, one row per bound worker, a lease expiry in a `DATETIME`.** Four statements, no
prepared statements, no transaction on the read path:

| Who | Statement | Purpose |
|---|---|---|
| worker, on `bindRead($queue)` | `INSERT … ON DUPLICATE KEY UPDATE seen = NOW()` | announce |
| worker, on `bindRead($queue)`, after the announce | `DELETE … WHERE seen <= NOW() - INTERVAL ttl*3 SECOND` | reap, once per worker start |
| worker, on `pickTask()`, on the renew's throttle | the same delete, ordered and batched | reap, again — see correction 13 |
| worker, on `pickTask()`, at most every `ttl / 3` s | the same insert-or-update | renew |
| worker, on `disconnect()` | `DELETE … WHERE token = "<token>"` | release early |
| publisher, on `hasWorkers($queue)` | `SELECT COUNT(*) AS workers … WHERE queue = … AND seen > NOW() - INTERVAL ttl SECOND` | count |

**The lease value is computed entirely by the server, so no clock can disagree.** This is the
strongest argument for the table, and it is better than Plan 9's Redis lease rather than merely
equal to it. Plan 9 has to fetch `TIME` from Redis on both sides and then compute `serverNow() + ttl`
in PHP, because a *score* has to be an absolute expiry. Here the row stores `seen = NOW()` and the
query computes `NOW() - INTERVAL ttl SECOND` — the addition happens server-side, in the same
statement that reads it. PHP's clock enters only the decision "has `ttl/3` elapsed since my last
renew?", and a skew there costs one extra or one missing renew, never a wrong lease. The adapter
already writes `NOW()` in three places (`:109`, `:177`, `:193`, decided in `UPGRADING:466-471`), so
this is the same convention, not a new one.

**One statement for announce and renew.** `INSERT … ON DUPLICATE KEY UPDATE` on a `UNIQUE` key over
the token is idempotent and atomic: no read-then-write, no transaction, no lost update between two
workers. This is strictly better than Plan 9's two-step `TIME` + `ZADD`, and it is why the write
side of this plan is one statement where Plan 9's was two.

**The reap is not on the read path, and it is not a correctness requirement.** In Plan 9 a
`ZREMRANGEBYSCORE` before `ZCARD` was **mandatory**, because `ZCARD` counts members regardless of
score and a stale lease would otherwise be counted forever. `SELECT COUNT(*) … WHERE seen > …` does
not have that problem: the predicate filters, so an expired row is invisible without being deleted.
So the publisher's `hasWorkers()` is **one read and no write** — a real difference from Plan 9,
where the read path wrote on every call. The reap exists only to stop the table growing, and it runs
where it costs nothing: **on `bindRead()`**, which a worker reaches once at startup, off both hot
paths — and, as correction 13 found, on `pickTask()` beside the renew, because "once per worker
start" is a statement about *where* that turns out to be a statement about *how often*. Deleting
everything older than `3 × workerTtl` rather than `1 ×` means a row is only removed
once it is far enough past expiry that no reader could be counting it.

**A missing table degrades to `false`, loudly at `debug` and quietly everywhere else.** The user has
to run the DDL and the adapter must not raise when they have not. A `SELECT` against a table that
does not exist throws `mysqli_sql_exception` with code **1146**; `attempt()` turns that into
`false` plus an `error` log, which is the correct outcome but is a **log flood** when the cause is a
forgotten `CREATE TABLE`: one `error` per `hasWorkers()` call from every web request, and one per
worker per `ttl/3` seconds. So the registry table's absence is detected once per adapter,
remembered, and logged at `debug` — the library cannot distinguish "you forgot the DDL" from "the server went away", and
the difference is a page of logs. Any other error keeps logging at `error`. This is the plan's one
piece of state beyond the token, and it is a boolean that only ever moves in one direction.
"Once" here means once per **adapter**, not once per process — see correction 12, and do not reach
for `static` to fix it.

**The registry is never load-bearing.** The announce, the reap, the renew and the release are each
`attempt()`-wrapped and the answer is discarded. A deployment that granted the queue's `SELECT`,
`INSERT` and `UPDATE` but not `DELETE` must not stop every worker in it. `bindRead()` therefore
still returns `true` unconditionally, exactly as it does today. This is a hard rule with tests behind
it (11.6).

**The registry is a table of its own, and that is what costs the DDL.** The one option with no
DDL is a heartbeat row inside `backq_jobs`, and the temptation is real enough to record: it is
also a data-corruption hazard rather than merely untidy, for the reason in "The problem". That
is why 11.1 is a new table and not a new column.

### What the answer means, stated precisely

`hasWorkers()` answers **"a worker was seen on this queue within the last `workerTtl` seconds"** —
the same sentence, and deliberately the same semantics, as Plan 9's Redis answer. Consistency
across adapters is worth something on its own: a user moving a queue from Redis to MySQL does not
get a new failure mode. The bias is the same too, and the false negatives are the same list:

- a worker **running a job longer than `workerTtl`** is not reported. This is a false negative
  (publish anyway) and the only fix is `workerTtl > longest job`. Say it in the README, or the
  first user with a 10-minute job files it as a bug. **Plan 10 has no such failure mode**, because a
  named lock is not a clock; that is the one place this plan is strictly worse, and it is the
  strongest argument for the lock.
- a worker that **started `ttl/3` seconds ago** is not reported until its first renew. The announce
  in `bindRead()` closes most of this window — a worker is visible from the moment it binds, not
  from the moment it renews — which is a smaller window than Plan 9's.
- a worker **blocked in `usleep($pickMissSleep)`** (`:124`, 5 s by default) *is* reported, which is
  the case that matters: it is waiting for exactly the job being published. The renew is placed
  before the transaction opens, so the 5 s sleep is not inside it (11.4).
- a worker that was **`kill -9`'d** is not reported after its lease expires, and its **row stays in
  the table** until a worker reaps it — on its next renew interval, not only when one next starts
  (correction 13). The answer becomes correct on its own; the
  footprint does not, and that is the operational difference from Plan 10.

## Measured, not assumed

Measured in `app-php83` (PHP 8.3.33, mysqlnd 8.3.33, `zend.assertions=-1` so `assert()` in `src/`
is compiled out). **There is no MySQL server anywhere in the test environment** — see the table.

| Question | Answer | How |
|---|---|---|
| Does the `mysqli` extension load? | yes, `mysqlnd 8.3.33` | `mysqli_get_client_info()` |
| Is there a `mysqli` service in `build/docker-compose.yaml`? | **no usable one** — see correction 1 | `docker compose config` |
| Is a mysqld reachable from the container? | no: `127.0.0.1:3306`, `mysql:3306`, `backq-mysql:3306` all refused/unresolved | `fsockopen` |
| How does the MySQL test layer get a link today? | **`createMock(mysqli::class)`** — 41 tests, no server | `MySqlAdapterTest::db()` |
| What does `mysqli::query()` return? | **no declared return type** — reads as `mixed` | reflection; hence `select()`'s `instanceof mysqli_result` narrowing (`:417`) |
| Does `write()` answer for a `SELECT`? | **no — it frees the result and returns `0`** (`:434-442`) | read the source |
| Is `mysqli_result::num_rows` a method? | **no** — it is a property | reflection, `hasMethod()` = 0 |
| Does the adapter already use the server clock? | yes: three `NOW()` writes | `:109`, `:177`, `:193`, `UPGRADING:466-471` |
| Are there prepared statements anywhere in this adapter? | no — every statement is interpolated and passed to `query()` | read the file |
| Can `JobConfig` take new fields? | yes, `final readonly` with 7 fields; `JobConfigTest` constructs positionally at `:34` and `:46`, so **append last** | read the test |

Two rows decide the shape of the plan. **There is no MySQL server**, so this repository has never
executed a single MySQL statement and every test in it asserts the *string* the adapter would have
sent to a mock — which is a real and useful thing to test, and is not evidence that the statement is
valid SQL, that `ON DUPLICATE KEY UPDATE` behaves as advertised, or that `INTERVAL ? SECOND`
interpolates the way the plan assumes. And **there are no prepared statements in this adapter**, so
`ttl` reaches the server as an interpolated `int`. That is safe *because* it is typed `int` from
`JobConfig`, and it is the kind of thing to write down: the same field changed from `int` to `string`
by a careless edit and the interpolation becomes a query built from a string.

**Both rows above were true when this plan was written. Neither is true now**, and the gap
between them is the plan's own argument: a `mysql80` service appeared in the compose file in the
working tree meanwhile — unparseable, never started, correction 1 — while the `MySql` tests stayed
at 41, every one of them a `createMock(mysqli::class)`. A service nobody started proves nothing,
and a mock proves nothing about the server. `MySqlLiveTest` is the first file in this repository
to execute a MySQL statement at all.

The four claims this section told the reader to distrust, from the manual and unmeasured at the
time, all four held up when measured: 1146 **is** `ER_NO_SUCH_TABLE`, `NOW()` **is** constant
within a statement, and `DATETIME` **does** come with a null default and an empty `EXTRA` (no
`ON UPDATE CURRENT_TIMESTAMP`, which is why 11.1's `DATETIME` is right and a `TIMESTAMP` there
would have been silently rewritten by the registry's own `UPDATE`). The server-side numbers that
were wrong were the ones the plan did *not* flag as guesses: `VALUES(queue)` is deprecated
(correction 2) and its stand-in code 1144 is a missing storage engine, not a lock wait
(correction 4). **Marking a claim "unmeasured" is not the same as doubting it — check which
claims you are actually not worried about.**

## Out of scope (considered and deliberately excluded)

- **A heartbeat row inside `backq_jobs`, or a `worker_token` column on it.** Rejected in "The
  problem": `putTask()`'s update-by-caller-chosen-id can rewrite a worker's row, and the column
  would have to be excluded from `pickTask()`'s hot read.
- **A MySQL named lock** (`GET_LOCK`/`IS_USED_LOCK`). This is Plan 10, and it is the alternative
  rather than a subset: it needs no DDL, has no TTL, has no reap, and its liveness is exact.
- **`information_schema.PROCESSLIST`.** The MySQL twin of the Redis `CLIENT LIST` Plan 9 rejected.
  It needs the `PROCESS` privilege, it is `O(sessions)`, it cannot distinguish a worker from any
  other sleeping client, and it cannot see a *queue*.
- **Inferring the answer from `sync = 'LOCK'` job rows.** Those are jobs in progress: a worker
  exists, but it is *busy*, which is the opposite of what the question asks, and an idle worker in
  the `usleep()` at `:124` leaves no row at all. `UPGRADING:461-465` justifies the stub with the
  clause "the query it would need is the same query that already knows the answer"; **that clause is
  false**, and 11.7 deletes it.
- **A reap on the publisher's path.** It would be a write in a web request, and the `WHERE seen >`
  predicate means the read is already correct without it.
- **Host, pid, and the last job id on the row.** This is the plan's best argument and it is
  deliberately *not* in 11.1: three more columns that the adapter would have to invent values for,
  and a schema a user has to run before they can use any of it. They are the obvious 5.2 if this
  plan lands and the "is anyone working?" question keeps coming up.
- **A tri-state answer.** `bool` is the 5.x contract (`UPGRADING:58-63`); "unknown" would need a new
  signature on two interfaces, three adapters and every third-party adapter.
- **A count.** `bool` is the return type (`AbstractAdapter.php:149`); a count is thrown away.
- **Heartbeats from `AbstractWorker`.** The adapter already sees the bind, the pick and the
  disconnect, and the adapter is what a user may replace. Keeping it in the adapter means a
  hand-written adapter does not silently lose the feature.

## How to execute this plan

Same two-phase ritual as Plans 1–9: **Phase A** writes the tests against the current stub (RED),
**Phase B** makes them pass. `JobConfig` gains two fields, not a class, so `composer dump-autoload`
is not required — run it anyway if 11.6 adds a test class, and before the suite regardless.

Phase A's live half is the half that cannot be faked, and 11.0 is what makes it possible. Note the
consequence that comes with it: `phpunit.xml` sets `failOnSkipped="true"`, so a skipped live test is
a **failed run**. Adding a MySQL service to compose is what makes the container mandatory for a
green suite; a host without it now fails for two services instead of one. That is the right outcome
(AGENTS.md already says all test work happens in `app-php83`) but it must be written in the README's
contributing notes so nobody reads it as a regression.

---

## 11.0 A MySQL service in the test environment (shared with Plan 10)

The single largest cost in this plan, and the reason it starts at 11.0 rather than at the code.
`build/docker-compose.yaml` has `app-php83`, `redis` and `composer-cache`. Add a service
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

Pin the version rather than using `latest`: a plan whose liveness argument is "a lease expires" needs
the server's `sql_mode` and time-zone behaviour in the diff, and those differ across 8.x.
`app-php83` needs `depends_on: mysql: condition: service_healthy`, and the port convention already
used for `redis` (`BACKQ_REDIS_PORT`) gets a `BACKQ_MYSQL_PORT` twin so a host-side run can reach
`127.0.0.1:13306`.

`tests/Adapter/MySql/Schema.php` gets the `CREATE TABLE backq_jobs` statement the existing MySql
tests assume but never run, plus the 11.1 DDL, as fixtures this plan's live test and Plan 10's reuse.

## 11.1 The schema

```sql
CREATE TABLE `backq_workers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(64) NOT NULL,
  `token` varbinary(16) NOT NULL,
  `seen` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `backq_workers_token` (`token`),
  KEY `backq_workers_queue_seen` (`queue`, `seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Correction 14: `token` is `varbinary(16)`, and it was not written as `varchar(64)` here for a
reason no plan noticed — a `utf8mb4` `varchar` refuses the token the adapter sends, with
`ERROR 1366`.** The type is what makes the statement work; the width is the smaller fact, and is
worth 82 KB on 10,000 rows, not a factor of sixteen. There is also a `KEY backq_workers_seen (seen)`
here that the plan below does not have; see correction 13.

Three choices, each load-bearing and each with a reason that is not obvious:

- **`datetime`, not `timestamp`.** On the pre-8.0.2 default `explicit_defaults_for_timestamp=OFF`,
  the *first* `TIMESTAMP` column in a table silently gets `DEFAULT CURRENT_TIMESTAMP ON UPDATE
  CURRENT_TIMESTAMP`. This table's first timestamp column is `seen`, so every `UPDATE` to a row
  would rewrite it — including an update the adapter did not intend. `DATETIME` has none of that
  behaviour and the adapter writes `NOW()` explicitly anyway, so nothing is lost.
- **`varchar(64)`, not `varchar(255)`.** 191 × 4 bytes = 764, just under the 767-byte InnoDB index
  prefix limit that predates `innodb_large_prefix`. The `queue` column is indexed, so 255 is a
  portability question and 191 is the answer that works everywhere. A queue name longer than 191
  characters is not a real case; a deployment that cannot create the table is a real one.
- **`UNIQUE` on `token`, not on `(queue, token)`.** A token is unique per adapter instance
  regardless of queue, so the uniqueness constraint is what `INSERT … ON DUPLICATE KEY UPDATE`
  keys on, and it is what makes the announce one statement. The `(queue, seen)` index is for the
  count and the reap; the `id` auto-increment is for a human reading `SELECT *`.

The adapter never runs DDL. The table is documented, the user creates it, and a user who does not is
a `false` answer rather than an exception (see the design section).

## 11.2 `JobConfig` gains the table and the lease length

```php
// src/Adapter/MySql/JobConfig.php — appended last; the test constructs positionally
/**
 * @param string $workerTable table holding one row per bound worker
 * @param int $workerTtl seconds a worker's lease stays valid without a renew
 */
public string $workerTable = 'backq_workers',
public int $workerTtl = 300,
```

`workerTtl` mirrors `RedisConfig::$workerTtl` from Plan 9 on purpose — same name, same default, same
meaning — so one concept exists across the library and the README has one sentence to write twice
with a different antecedent. Validate it exactly as `RedisConfig` does, with a minimum (Plan 9 used
`WORKER_TTL_MIN = 5`): a `workerTtl` of `0` means every lease is expired the moment it is written,
`hasWorkers()` answers `false` for the lifetime of the process, and there is no error to notice.

`workerTable` is interpolated into SQL exactly like `table` already is, so it carries the same
trust level and the same caveat: it is configuration, not input, and the README says so.

## 11.3 `bindRead()` announces, `disconnect()` releases

```php
/**
 * The token identifying this adapter as a worker, null while it is not one
 */
private ?string $workerToken = null;

/**
 * The unix second of the last lease write, null before the first one
 */
private ?int $renewedAt = null;

/**
 * The queue this adapter is bound read to, captured at bind time because
 * pickTask() has no queue argument to renew against
 */
private string $lastQueue = '';

/**
 * Set once the registry table is found to be missing, so the rest of this adapter's life
 * does not log a page of errors per hasWorkers() call
 */
private bool $registryMissing = false;

#[Override]
public function bindRead(string $queue): bool
{
    $this->attempt(__FUNCTION__, function () use ($queue): void {
        $this->workerToken ??= $this->makeToken();
        $this->lastQueue = $queue;
        $this->lease($queue);
        $this->reap();
    });

    /**
     * A worker that could not write the registry is still a worker. bindRead() cannot
     * fail on the registry's account, or a deployment that revoked one privilege
     * would stop every worker in it.
     */
    return true;
}

#[Override]
public function disconnect(): bool
{
    $token = $this->workerToken;
    $this->workerToken = null;
    if (null === $token) {
        return true;
    }

    $this->attempt(__FUNCTION(), function () use ($token): void {
        $this->write('DELETE FROM ' . $this->config->workerTable . ' WHERE token = "' . $token . '"');
    });

    return true;
}
```

**Correction 14: the token is a version 7 UUID, sent as `UNHEX("…")` — not a pid and 16 hex
characters, and not by a factor of digits but because the raw 16 bytes cannot go into a string
literal at all.** See the end of this section for the three measurements. Everything below about
`getmypid()` answering `int|false` was true of the shape this plan specified and is moot for the
shape that shipped; what survives is the *lesson*, which is the fourth time in this feature that
psalm caught a value a driver types more honestly than the concatenation assumed, and the reason the
`escape()` note two paragraphs down still matters.

**The token as this plan specified it was `(string) getmypid() . '-' . bin2hex(random_bytes(8))`.** The random half is what
makes it unique — two worker processes on one host would otherwise share a pid at different times —
and the pid is decorative, for the human reading the table. `getmypid()` answers `int|false`, so it
cannot be concatenated directly; `is_int($pid) ? $pid : 0` is the whole fix, and it is the third time
in this feature that psalm has caught a value a driver types more honestly than the concatenation
assumed (Plan 9's corrections 4). `random_bytes()` throws on failure; inside `attempt()` that is a
logged `error` and no token, which is the correct degradation.

**`disconnect()` clears the token before the release, and uses a local.** A worker that could not
write its release still must not believe in a lease it no longer has, and the release is keyed by the
*token*, so the value has to be read out before the property is cleared. This is the same
local-then-clear shape as Plan 10's `disconnect()`, and the same reason.

**The token is not escaped where it is interpolated** because it is a hex string the adapter itself
generated — but say that in a comment rather than leaving it to be discovered. A future change that
puts a pid-derived or user-derived character in it needs the `escape()` call that `:110` uses for a
job id.

## 11.4 `pickTask()` renews, at most every `ttl / 3` seconds

```php
#[Override]
public function pickTask(?int $timeout = null): bool|array
{
    /**
     * Before begin_transaction(), and outside every catch below: a renew that
     * throws is swallowed by its own attempt(), so it can neither roll back the pick
     * nor be logged as a pick failure. Inside the transaction it would lengthen the
     * window the lock is held, and the usleep(pickMissSleep) at the end of this
     * method is deliberately outside the transaction already.
     */
    $this->renew();

    try {
        $this->db->begin_transaction();
        // ... unchanged ...
```

```php
/**
 * Push the lease forward, at most once per workerTtl / 3 seconds
 *
 * A worker is visible from the moment it binds, so this is about staying visible, not
 * about becoming visible. time() is the right clock for the interval and the wrong
 * clock for the value: the lease itself is NOW() on the server, and PHP's clock can
 * only make this fire early or late, never write a wrong lease.
 */
private function renew(): void
{
    $token = $this->workerToken;
    if (null === $token || $this->registryMissing) {
        return;
    }

    $now = time();
    if (null !== $this->renewedAt && $now - $this->renewedAt < intdiv($this->config->workerTtl, 3)) {
        return;
    }

    $this->attempt(__FUNCTION__, function () use ($token): void {
        $this->lease($this->lastQueue, $token);
    });
    $this->renewedAt = $now;
}

/**
 * Write or push forward this worker's lease
 *
 * One statement: INSERT ... ON DUPLICATE KEY UPDATE on the UNIQUE token key is
 * idempotent and atomic, so there is no read-then-write and no transaction. The
 * queue and token are in the INSERT branch, so a worker that is re-bound to another
 * queue on the same adapter moves rather than appearing on both.
 */
private function lease(string $queue, ?string $token = null): void
{
    $token ??= $this->workerToken ?? '';
    $sql = 'INSERT INTO ' . $this->config->workerTable . ' (queue, token, seen) VALUES ("'
        . $this->escape($queue) . '", "' . $this->escape($token) . '", NOW()) '
        . 'ON DUPLICATE KEY UPDATE queue = VALUES(queue), seen = NOW()';
    $this->write($sql);
}
```

`VALUES(queue)` is the 5.7-and-earlier spelling of referring to the proposed row; MySQL 8.0.20
deprecates it in favour of a row alias. Use the form the pinned server accepts and record which, or
the upgrade will produce a deprecation notice in somebody's log. This is measured in 11.6.

`$lastQueue` is the queue name captured in `bindRead()`, because `pickTask()` has no queue argument
to renew against — the same reason Plan 9's registry is keyed by a name captured at bind time.

**The cadence arithmetic is `intdiv(workerTtl, 3)`, and `$renewedAt` is set even when the write
failed.** A failed renew is a failed lease; if the throttle clock advanced anyway the next attempt
would be a full `ttl/3` away, so a transient error would cost a third of the TTL of visibility. Set
it only on the way that worked, which means the throttle reads "time of the last *attempt*" and the
retry is bounded by the same interval.

## 11.5 `hasWorkers()` reads the table

```php
/**
 * Answered from the registry: a worker is one that held a lease on this queue within
 * the last workerTtl seconds. The predicate is the whole correctness argument, so the
 * publisher's path is one read and no write - an expired row is invisible without
 * being deleted, which is why there is no reap here the way there is on Redis.
 */
#[Override]
public function hasWorkers(string $queue): bool
{
    return $this->attempt(__FUNCTION__, function () use ($queue): bool {
        if ($this->registryMissing) {
            return false;
        }

        $rows = $this->select(
            'SELECT COUNT(*) AS workers FROM ' . $this->config->workerTable . ' '
            . 'WHERE queue = "' . $this->escape($queue) . '" '
            . 'AND seen > NOW() - INTERVAL ' . $this->config->workerTtl . ' SECOND'
        );

        return (int) ($rows[0]['workers'] ?? 0) > 0;
    });
}
```

**`select()`, never `write()`.** `write()` (`:434-442`) frees a result set and returns `0` for any
statement that produced one, so building `hasWorkers()` on it would answer "no workers" on every
call, forever, with a green suite behind it. This is the MySQL twin of the `getRedis()` trap Plan 9
hit, and it is the one to write down twice.

**`COUNT(*)`, not `$result->num_rows`.** `num_rows` is a property, not a method (measured), and
`COUNT(*)` keeps the answer in a column this adapter already knows how to read as `MYSQLI_ASSOC`.

**`INTERVAL <int> SECOND` is interpolated, and the `int` type is what makes that safe.** There are no
prepared statements in this adapter, so a `ttl` that were a string built from a request would be a
query injection. It is a constructor-promoted `int`, validated in 11.2, and the docblock on the
field says so.

**`NOW()` is constant within a statement**, so `seen > NOW() - INTERVAL … SECOND` compares every row
against one instant. No reap runs on this path, so there is no second statement to drift between.

**The `registryMissing` short-circuit is the one asymmetry**, and it is deliberate: every other
failure is `error`-level and every call pays for the query, while a missing table is a permanent
condition the user must fix and a log line that has already said so. It is set in the `catch` of the
one place that classifies errors, and only for code **1146**.

## 11.6 Tests

**Offline, in `tests/Adapter/MySqlAdapterTest.php`** — 41 tests today, all mock-based. **Fifteen added**, in the
fourteen items below (item 12 is two tests), none of which needs a server. They assert the *strings*, which is what this layer is for:

1. `testBindReadWritesALeaseForTheQueue` — one statement, and it is
   `INSERT INTO backq_workers (queue, token, seen) VALUES ("news", "<token>", NOW()) ON DUPLICATE KEY UPDATE queue = VALUES(queue), seen = NOW()`.
   The token is 32 hex characters inside `UNHEX()`, and the assertion matches the UUIDv7 shape with
   a pattern — the version nibble and the variant nibble are pinned, the timestamp and the random
   tail are not — while asserting the *name* parts literally: the table name is a contract, exactly
   as `WORKERS_KEY` is in Redis. **Correction 14 moved the token, and with it the pattern**; the
   decoded timestamp is asserted separately, in `testTheTokenEncodesTheMomentItWasMinted`.
2. `testBindReadTakesTheLeaseOnlyOnce` — the token is minted once, so a second `bindRead()` does not
   produce a second row. The counterpart of Plan 10's MySQL 8 counter trap and for the same reason:
   `$workerToken ??=`.
3. `testBindWriteTakesNoLease` — a publisher-only bind issues no statement. The structural reason a
   publisher cannot find itself.
4. `testBindReadReapsRowsOlderThanThreeLeases` — the `DELETE … WHERE seen <= NOW() - INTERVAL 900
   SECOND`, and that the interval is `3 × workerTtl`, not `workerTtl`.
5. `testBindReadStillReturnsTrueWhenTheStatementThrows` — `true` plus an `error` log. The
   never-load-bearing rule.
6. `testHasWorkersIsTrueWhenALeaseIsLive` — `[["workers" => "1"]]` → `true`, and the `int` cast is
   what reads `COUNT(*)`'s string.
7. `testHasWorkersIsFalseWhenNoLeaseIsLive` — `[["workers" => "0"]]` → `false`. This **replaces**
   `testHasWorkersReportsNotSupported()` and is the only guard against an "always true" bug.
8. `testHasWorkersCountsOnlyTheGivenQueue` — the `WHERE queue = "…"` predicate is asserted, so
   `hasWorkers('news')` cannot be satisfied by a worker on `mail`. This is the per-queue guarantee
   the shared job table cannot give on its own, and it deserves a test of its own.
9. `testHasWorkersIsFalseWhenTheStatementThrows` — `false` plus an `error` log.
10. `testHasWorkersLogsAMissingRegistryTableOnceAtDebug` — throw `mysqli_sql_exception('...', 1146)`
    twice, assert `true` (nothing raised) and exactly one `error` log, and that the second call
    issued **no statement**. The de-duplication is a behaviour, so it needs a test.
11. `testAnUnrelatedStatementFailureStillLogsAtError` — code 1144 (table lock wait timeout) is
    *not* the missing-table case and must keep logging at `error` every time.
12. `testDisconnectDeletesTheLeaseItWrote` and `testDisconnectTouchesNothingWhenBindReadNeverRan` —
    the release names the token it took, and a publisher's `disconnect()` writes nothing.
13. `testPickTaskRenewsAtMostOncePerThirdOfTheLease` — three `pickTask()` calls with
    `pickMissSleep` at 0: one `INSERT`, no more. Then a fourth after `time()` is faked past the
    interval: a second `INSERT`. The throttle is a real behaviour with a real off-by-one, and a
    worker that renewed on every pick would be a hot-path write nobody notices until the database
    notices.
14. `testPickTaskSurvivesAFailedRenew` — the renew throws, the pick still runs, and the throttle does
    not advance far enough to skip the next attempt.

Plus `tests/Adapter/MySql/JobConfigTest.php`: the `workerTable` and `workerTtl` defaults, the
override, and the rejection of a `workerTtl` below the minimum. The nine existing tests construct
`JobConfig` positionally at `:34` and `:46`, so appending the fields last is what keeps them
passing — worth an assertion, because the next person to insert a field in the middle will break
them.

**Live, in a new `tests/Adapter/MySqlLiveTest.php`.** The first MySQL test in this repository to
execute SQL, and the only thing here that can be wrong about MySQL. It mirrors `RedisAdapterTest`'s
reachability guard and asserts:

- the 11.1 DDL actually creates, and a row survives a `NOW()` round trip — the `DATETIME` and the
  `191` are the two claims from the manual that a mock cannot check.
- `INSERT … ON DUPLICATE KEY UPDATE` twice on one token leaves **one** row with a fresh `seen`, and
  is accepted on the pinned server without a deprecation notice (11.4's `VALUES()` question).
- a lease written with `seen = NOW() - INTERVAL 400 SECOND` is not counted at `workerTtl = 300`, and
  one at `- INTERVAL 100 SECOND` is. The predicate is the correctness argument, so it is tested
  against the server's own clock rather than PHP's.
- a **publisher's `hasWorkers()` does not write**: row count in the table is unchanged across the
  call. This is the claim that separates this plan from Plan 9, and it is worth a test.
- two `MySql` adapters on **separate links**, one `bindRead()` and one publisher, see each other;
  and `bindWrite()` on a second publisher-adapter is not seen.
- the missing-table case: a `hasWorkers()` against a dropped table returns `false`, raises nothing,
  and logs once at `debug`.
- `kill -9` a child process holding a lease: after `workerTtl` the answer flips to `false` and the
  **row is still there**, and a later `bindRead()` reaps it. Both halves matter — the first is the
  correctness claim and the second is the operational cost, and a plan that only tested the first
  would be hiding its own downside.

## 11.7 Docs

- `README.md:164-191`: the `MySQL` row's `hasWorkers` cell goes `*` → `✓`, and the `*` legend keeps
  `setWorkTimeout()`. Replace the `MySql::hasWorkers() reports no workers without checking` bullet
  with a paragraph in the shape of the Redis one, plus **the DDL**, because this is the plan where
  the reader has to run something: a `CREATE TABLE` block, the `workerTtl > longest job` warning
  that Plan 9's paragraph already carries, and the statement that a table the user did not create
  degrades to `false`.
- `UPGRADING`: amend the `:461-465` bullet — the method is no longer a stub, and the clause "the
  query it would need is the same query that already knows the answer" is **deleted**, because it is
  false and the reader now needs to know what actually answers it. Add `workerTable` and `workerTtl`
  to the `JobConfig` constructor listing, and say plainly that this is the one feature in the library
  that requires a DDL.
- `AGENTS.md`: the `MySql` psalm-suppression count, the new `write()`-on-a-`SELECT` trap, and the
  "two services or the suite cannot be green" consequence of 11.0. Re-measure the counts, do not
  predict them.
- `plans/`: this file's status block, and Plan 10's cross-reference.

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
  before, 402 / 1040 after, with the whole delta attributed. The MySQL layer starts at
  `MySqlAdapterTest` 41 tests / 109 assertions and `JobConfigTest` 9. Every new test is counted on
  the way in; a drop means a deleted test.
- **`MySqlLiveTest` must not skip.** `failOnSkipped="true"` turns a skip into a failure, which is
  the right outcome on a host with no MySQL. In the container the skip count must be zero — printed,
  not inferred from a green line.
- **The green mock suite is not evidence.** `MySqlAdapterTest` mocks `mysqli`, so all fifteen
  offline tests pass whether the DDL is valid, whether `ON DUPLICATE KEY UPDATE` works, and whether
  `INTERVAL 300 SECOND` means three hundred seconds. Say that in the PR.
- **No rows left behind.** The live test's teardown truncates `backq_workers` and drops the schema
  fixture. A leaked live row makes a later `hasWorkers()` test pass for the wrong reason, which is
  the stale-fixture failure Plan 7 warns about — and here the row is *in the table the tests read*.
- **Re-measure the psalm-suppression counts.** `src/Adapter/MySql.php` has 3 today (`:58`, `:59`,
  `:331`) and `src/Adapter/` has 14. Four new methods that log through `$this?->logger` will each
  want their own `TypeDoesNotContainNull` / `PossiblyNullPropertyAssignment` pair, so expect 3 → 7
  in the file and 14 → 18 across the directory. **Measure and attribute each one.** `getmypid()`
  will also need its own narrowing, and the `INTL`-free `time()` return is a plain `int` so it will
  not.
- **`composer dump-autoload` is not needed** — 11.2 adds fields, not a class. `MySqlLiveTest` and
  `MySql\Schema` are new classes, so it *is* needed before the suite; its absence shows up as a
  `Trait "…" not found`-shaped fatal, which `check-classes.php` catches first.

## Traps

- **`write()` returns `0` for anything that produced a result set** (`:434-442`). `hasWorkers()` is a
  `SELECT`, so it must go through `select()`. Built on `write()`, it answers `false` forever and the
  mock suite is green. This is the MySQL version of the `getRedis()` trap Plan 9 documented: **a
  failure mode the mocked layer cannot reach.**
- **`mysqli` has no `get_lock()` method, and no `RELEASE_LOCK` either.** Measured. If you are
  reading this while considering Plan 10 as well: every lock operation there is a `SELECT` too, which
  is why both plans share this trap.
- **`mysqli::query()` has no declared return type** (measured), so `select()`'s
  `instanceof mysqli_result` narrowing (`:417`) is what stands between a result set and a
  `TypeError`. Do not "simplify" the helper.
- **A `DELETE` is the only release, and it needs a `WHERE`.** `write()` returns
  `max(0, affected_rows)`, so a release that matched nothing returns `0` and a release that matched
  two rows would mean two adapters share a token — which the random half of the token prevents.
  Asserting on the row count is how a test catches it.
- **`getmypid()` answers `int|false`**, so it cannot be concatenated into a token directly. The pid
  is decorative; the random half is what makes a token unique.
- **`TIMESTAMP` would have taken `ON UPDATE CURRENT_TIMESTAMP` on the first column** under the
  pre-8.0.2 default, rewriting `seen` on any update. `DATETIME` plus an explicit `NOW()` is why the
  schema says what it says.
- **`INTERVAL <int> SECOND` is interpolated** because this adapter has no prepared statements. The
  `int` type from `JobConfig` is what makes that safe; a field changed to `string` is a query
  injection and phpstan will not catch it.
- **`VALUES(queue)` is deprecated on 8.0.20+.** Pin the server, measure which spelling it accepts,
  and record it — the upgrade that changes this is exactly the upgrade that should not change
  behaviour quietly.
- **`MySql` has no `ConnectionState` and `isReady()` is always `true`.** So `attempt()`'s
  precondition gate never fires, `preconditionFailed()` (`:318`) is unreachable, and `$workerToken`
  is the only record of whether this adapter is a worker. It is also what keeps a publisher from
  finding itself — by construction, not by an explicit exclusion.
- **The reap must not move to the publisher's path.** The read is already correct without it, and a
  write in a web request is a write in a web request.
- **The reap interval is `3 × workerTtl`, not `workerTtl`.** A row is only deleted once it is far
  enough past expiry that no reader could be counting it.
- **The reap's scope is "a worker is running", not "a worker started".** `bindRead()` runs once per
  process, so a reap that lives only there leaks one row per crash in a stable fleet. It also
  needs its own `attempt()` from the lease's, its own `KEY (seen)`, and a `LIMIT` (correction 13).
- **Do not put the heartbeat in `backq_jobs`, and do not add a `worker_token` column to it.**
  `putTask()`'s `UPDATE … WHERE id = "<caller's id>"` (`:144-218`) can rewrite a row that is not its
  own, and the column would have to be excluded from `pickTask()`'s hot read.
- **`failOnSkipped="true"` means the container is now mandatory.** Two services, not one. That is
  the honest consequence of 11.0, not a regression.

## If you take both plans

Plan 10 and Plan 11 answer the same method, and combining them is one line:

```php
return $this->lockSays($queue) || $this->tableSays($queue);
```

Worth doing only in that order: the lock is exact, costs no write, and needs nothing installed, so it
short-circuits, and the table is the fallback that gives the operator a row to read. Never the
reverse — `hasWorkers()` must not write a row when the answer is already available. What survives
unchanged from this plan in that case: the `bindRead()` and `disconnect()` hooks, the `attempt()`
wrapper on every write, and `JobConfig::$table` doing double duty as the lock's queue scope. What
does not: the renew in `pickTask()` (11.4) and `workerTtl` (11.2), neither of which the lock needs —
and if the lock answers first, the table stops being written, so an operator reading it must be told
that its absence means "the lock answered", not "no workers".

## Corrections this plan needed

The design held. Fourteen things in the sketches and the surrounding text did not, and every one
of them was found by running something rather than by reading harder. Seven of the fourteen could
not have been found by the mocked test suite at all, which is the general lesson: **a mock can
prove a string was built, and this feature is mostly about whether MySQL accepts the string.**

**1. `build/docker-compose.yaml` did not parse, and had never been started.** 11.0 says the file
"currently has `app-php83`, `redis` and `composer-cache`" — true of `HEAD`, but a `mysql80`
service and an `x-mysql` anchor had been added in the working tree since, and the anchor was
*defined after the service that uses it*, so `docker compose config` failed and `composer app-up`
could not start anything at all. Fixed by moving `x-mysql` above `services:`, pinning
`container_name: backq-mysql` the way the other services are pinned, adding
`depends_on: {mysql80: {condition: service_healthy}}` and the `BACKQ_MYSQL_*` variables to
`app-php83`, and deleting an `expose: 4306` that meant nothing. **A block in a compose file that
has never run is a sketch in a YAML file**, which is this plan's own argument against itself.

**2. `VALUES(queue)` raises `Warning 1287` on the pinned server, and the row alias has a version
floor.** 11.3 offered the 5.7 `VALUES(queue)` spelling or the 8.0.19+ `AS new` spelling and asked
the implementer to record which. Measured on 8.0.46: `VALUES(queue)` is deprecated, and `AS new` is
clean but puts a version floor on the adapter for no benefit. The lease therefore names the queue
**literally** in the `UPDATE` clause:

```sql
INSERT INTO backq_workers (queue, token, seen) VALUES ("q", "t", NOW())
ON DUPLICATE KEY UPDATE queue = "q", seen = NOW()
```

Accepted by 5.7 and 8.0 alike, measured to raise no warning, and it is what makes a worker
re-bound to another queue *move* rather than appear on both. `MySqlLiveTest` asserts this by
issuing the adapter's own statement and then reading `SHOW WARNINGS` — a mock cannot see a
deprecation.

**3. The bold lead contradicts the body, and the body is right.** 11.4 says in bold that
`$renewedAt` is set "even when the write failed", then three paragraphs later explains why that
would cost a third of the lease length of visibility. The bold lead is wrong: `renew()` sets the
throttle from `attempt()`'s return value, so a failed renew is retried on the next cycle. This was
not caught by reading — the first implementation followed the bold lead, and
`testPickTaskSurvivesAFailedRenewAndRetriesIt` failed with 2 lease writes where it expected 3.

**4. `1144` is a missing storage engine; the lock-wait timeout is `1205`.** 11.5's second test asks
for a "non-1146 error" and uses 1144. `ER_NO_SUCH_TABLE` **is** 1146 (so the de-duplication is
right), but the test's stand-in is a nonsense code, and the code the implementer reaches for next is
also likely to be wrong: 1205 is `ER_LOCK_WAIT_TIMEOUT`. The test now uses 1205 and the constant is
named `ERROR_NO_SUCH_TABLE` with the measurement in the docblock, because a bare `1146` in a
comparison reads as a magic number and a magic number in this position gets "helpfully" changed.

**5. `attempt()` takes `Closure():bool`, so the plan's `function (): void` closures do not fit.** The
sketches for `bindRead()` and `disconnect()` pass `function (): void` to `attempt()`. Psalm rejects
both with `InvalidArgument` — the closures return `true`, exactly as the ones in `renew()` and
`reap()` already had to. This is a real failure, not a style note: it is psalm, not `php -l`, that
names it.

**6. There is no catch in which to classify the missing table.** 11.5 says the flag "is set in the
`catch` of the one place that classifies errors". There is no such catch: `AbstractAdapter::attempt()`
is the classifier, and it logs **every** `Throwable` at `error` unconditionally, which is the whole
point of the family. Classifying 1146 therefore requires a **nested** `try` around the `select()`
that re-throws anything else, so that `attempt()` sees it and logs it. The plan's own test item 5 —
"assert exactly one `error` log" for a 1146 — contradicts the design section's "logged once at
`debug`"; the design is right and the test now asserts `debug`.

**7. The psalm-suppression prediction was wrong in the direction that matters.** The plan predicts
`MySql.php` 3 → 7 and `src/Adapter/` 14 → 18, on the rule that a method logging through
`$this?->logger` needs its own `PossiblyNullPropertyAssignment`. That rule is true of `Redis`, whose
logger is a nullable **property**, and false of `MySql`, whose logger is a mandatory constructor
**parameter** that the class docblock's two existing suppressions already cover. All four were
written; all four were deleted after psalm reported nothing without them. Measured: **3** and
**14**, unchanged. Psalm does not report an unused suppression, so a predicted count is a guess
wearing a number's clothes.

**8. The plan's test list omits a test it invalidates.**
`MySqlAdapterTest::testTheQueueNameIsIrrelevantTheTableIsTheQueue` asserts that `bindRead()` issues
**no** query at all, and 11.3 makes it write one. The test was rewritten rather than deleted — its
real claim (one job table holds every queue, so the queue name never reaches it) is still true, and
is now asserted as "every statement `bindRead()` writes names `backq_workers` and none names
`backq_jobs`". **A plan that changes behaviour must list the tests it breaks.** It did not.

**9. Half of the renew-throttle test cannot be proved with a mock, and the plan put both halves in
one offline test.** "Renews again once the interval has passed" needs the wall clock to move, and
proving it offline means either sleeping 100 seconds or a clock seam the library does not have. With
the default `workerTtl: 300` the interval is 100 s and "three cycles in one second, one write" is
**deterministic** — that half stayed offline. The other half moved to `MySqlLiveTest` with
`workerTtl: 5`, whose interval is 1 s, and one `sleep(2)`. That is the only test in the suite that
sleeps, and it is 2 seconds.

**10. The `kill -9` child is not needed to test what it was testing.** 11.6 asks for a spawned child
to be killed. A killed worker leaves exactly one row whose `seen` is old, and the test can write
that row directly with `NOW() - INTERVAL 3600 SECOND`. Same assertion, no process management, no
second link, no `proc_open`, no sleep, and no flake from a child that has not finished starting. The
`hasWorkers()` half is unchanged and is the one that matters; only the fixture is cheaper.

**11. The two MySQL privileges worth measuring were both measured, and the answer is wider than the
plan assumed.** A temporary `SELECT`-only user gets a working `hasWorkers()` and `DENIED 1142` on
every write; adding `INSERT`/`UPDATE`/`DELETE` makes everything work; removing only `DELETE` leaves
the lease working and denies the reap and the release. So the *only* privilege worth caring about is
`SELECT` on the registry — a user without `INSERT` still gets the answer, and a user without `DELETE`
still gets a working feature with a table that grows. That is the case for
`testBindReadStillAnswersTrueWhenTheRegistryIsMissing`, and it is stronger than the plan's
"best-effort" wording: the registry is observability, and observability that can stop a worker is
not observability.

**12. "Detected once" means once per adapter, not once per process.** The plan says the missing
table is "detected once, remembered" and the sketch describes the flag as lasting "for the rest of
the process". Both are true for a worker, which builds one adapter and loops — which is the case
the de-duplication exists for. Neither is true for a publisher under PHP-FPM, which builds a
fresh adapter per request, so the `debug` line is per request. What actually bounds the noise is
the **level**, not the flag, and the `README`, `UPGRADING` and the property's own docblock now say
so instead of promising "once per process".

The tempting fix is `private static bool $registryMissing`, and it is a trap: two adapters in one
process pointed at two different `workerTable` values — a multi-queue setup, and something
`MySqlLiveTest` does on purpose — would share the flag, so the first one's missing table would
silently answer `false` for the second one. **Process-wide mutable state on a configured object is
a cross-adapter leak, not a cache.** Per-instance is the correct scope and the log volume is the
honest price of it; a production handler that ignores `debug` sees nothing either way.

**13. The reap's scope is an accident of where it was written, and it is the one thing in this
plan that leaks in a stable deployment.** 11.4 puts the reap in `bindRead()`, and says so without
argument. `bindRead()` runs **once per process**, before the worker's `while (true)` loop
(`src/Worker/AbstractWorker.php` `:193`, the loop at `:263`), so a reap that lives only there
fires once per worker start. A fleet that is not restarting anything therefore never reaps again,
and every `SIGKILL`, OOM-kill and fatal leaves a row behind permanently. Measured before the fix:
7035 pick cycles over three seconds left a 4000-second-old row untouched at a 15-second threshold.
It read `hasWorkers() === false` throughout, so the **answer was never wrong** — the table just
never shrank, which is invisible until someone counts rows.

The fix is the reap also running inside `renew()`, on the throttle it already has, so the scope is
"a worker that is running" rather than "a worker that started". Two consequences the plan did not
have, both forced by making it periodic:

- The `DELETE` is now a statement the server sees from every worker every `workerTtl / 3`
  seconds, so it is **bounded**: `ORDER BY seen ASC LIMIT 1000`. A backlog drains over a few
  intervals rather than in one long transaction holding row locks, and a user with a truncated
  history is the case that makes 12 rows a lie. Oldest first is the only order worth deleting in —
  a row past three lease lengths is past three lease lengths whichever end it is.
- `ORDER BY seen` is the first clause in the feature that filters on `seen` **alone**, because the
  reap is the one statement that is not queue-scoped, so it is the one statement the
  `(queue, seen)` index cannot serve. It needs `KEY backq_workers_seen (seen)` of its own.
  Measured on 8.0.46 with `EXPLAIN`: with it, `type=range` on that key; without it, `type=ALL`,
  `key=NULL` and **`Using filesort`**, because the `ORDER BY` then has nothing to read in order. A
  scan *and* a sort, on a schedule. The count is unaffected by that drop, which is what makes the
  two keys distinct rather than one duplicated.

And a third thing, which is the reason the reap is a separate `attempt()` from the lease rather
than sharing one. The first implementation put both inside `bindRead()`'s single `attempt()`,
which is what the plan's sketch does, and it is a **silent** leak: a user granted
`SELECT`/`INSERT`/`UPDATE` but not `DELETE` — the exact case 11.5 worries about and 11.6 measures —
fails the lease's `attempt()` first, so the reap never runs. Nothing else goes wrong. The leases
work, the picks work, `hasWorkers()` answers correctly, and the table grows forever with one
`error` line per interval per worker. `announce()` now runs them as two calls from both
`bindRead()` and `renew()`, each logging its own failure.

The regression test is `MySqlLiveTest::testARunningWorkerReapsADeadPeerWithoutRestarting`, and its
**ordering is the test**: a reap on the first pick would have passed against the old code and
proved nothing, so the dead peer is seeded *after* the startup reap has already run, the next pick
is asserted to leave it alone because it is inside the interval, and only the pick after a
`sleep(2)` is allowed to remove it. `testTheReapIsBoundedAndTheNextOneFinishesTheJob` is the
counterpart for the `LIMIT`: 1200 qualifying rows, 1000 taken, and the next reap finishes the
job, so the bound is a rate rather than a skipped cleanup.

**14. `token varchar(64)` and `getmypid() . '-' . bin2hex(...)` are a shape the server does not
accept, and the reason is not size.** §11.3's DDL and §11.4's token are both sound as written and
both wrong against a live server, in the same way: the column type constrains the value, and the
plan never asked what a `varchar` will accept.

The shipped token is a **version 7 UUID**, 16 bytes, sent as `UNHEX("…32 hex characters…")`, and the
column is `varbinary(16)`. Three measurements, in the order they matter:

- **A `utf8mb4` `varchar` refuses the token outright.** `UNHEX()` returns raw bytes, and inserting
  16 of them into `varchar(64)` is `ERROR 1366 (HY000): Incorrect string value: '\x90c)\xA1\xB2\xC3…'
  for column 'token' at row 1`. So the type change is not an optimisation, it is what lets the
  statement run at all, and a table created from an older copy of the DDL does not carry a wider
  index — it rejects the write, inside `attempt()`, as a logged `error` and no lease.
- **The raw bytes must never be interpolated, hex must.** A random byte is `0x00` half the time and
  `0x22` half the time, so the raw form ends the string literal early: measured, the very first
  token produced `You have an error in your SQL syntax … near '??ua?|", NOW())' at line 1`. Eight
  bytes of randomness guarantees this rather than making it likely.
- **`UNHEX()` is not a parser.** It answers `NULL` for anything that is not pairs of hex digits,
  and `NULL` into a `NOT NULL` column is a *second, quieter* failure. So the dashed spelling —
  `UNHEX("01906329-a1b2-7c3d-…")`, which is how a human writes one and which a future reader will
  "helpfully" add — is not cosmetic, it is a different bug. The dashed form is for the log line.

The width is real but smaller than the argument usually claims, so it is worth stating honestly:
on 10,000 rows, `ANALYZE TABLE` reports `Index_length` 540,672 B for `varchar(64)` against 458,752 B
for `varbinary(16)` — **82 KB, 15%** — because the real tokens were 20 to 32 characters, not 64, so
the utf8mb4 storage was already small. The declared worst case is the honest framing: **256 bytes
per key down to 16.**

And the part the change does *not* buy, which is the part a plan would be tempted to claim:
**the id is not monotonic, and this schema could not use it if it were.** 20,000 tokens minted back
to back came out strictly ascending 50.1% of the time, because within one millisecond the order is
decided by the random bits — RFC 9562 says so. Ascending bytes would matter only for the
**clustered** key, where insertion order buys locality, and the clustered key here is the
auto-increment `id`; `token` is a secondary UNIQUE key read by equality. So "time-based" here
buys a *legible* timestamp — `SELECT HEX(token)` says when a lease was minted without joining
anything — and the width. Claiming an insert-locality benefit for this table would be claiming a
property the table does not have.

Two smaller consequences. The embedded time is **PHP's** clock while `seen` is the **server's**;
nothing compares them (the lease predicate is on `seen` alone), so a host with a wrong clock writes
a token with a wrong time in it and an expiry that is still right — the token's timestamp is a
diagnostic, never a source of truth. And the pid used to be in the token, which made "two rows means
two processes" readable; a UUID takes that away, so `testTwoAdaptersNeverShareAToken` is the test
that has to stand in for the reassurance a pid used to give for free.

**A column type is a constraint on the value, not a size setting, and the plan's DDL was the only
place that could have said so.** Everything in correction 14 was found by inserting a token into a
real `varchar`, which is a one-line experiment and not something a mocked suite can express: the
mock answers `mysqli_result` for every statement, so a `varchar` fixture rejects nothing and the
`1366` is invisible until production. The general lesson is the one from the top of this section,
once more — **the string being right is not the statement being run**, and now also that the
*column* being right is not the statement being run.

**A design that names where a statement runs has also named how often it runs, and the two are
not the same question.** "Runs on the worker's startup" reads as a statement about *when* and is
really a statement about *how often*, and the plan never asked the second question. Correction 3
is the same shape of mistake in the opposite direction — a plan whose bold lead and body
disagreed about a throttle — and the two together are why this file is longer than the plan.
