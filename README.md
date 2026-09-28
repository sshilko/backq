# BackQ

Background **queue processing** for PHP — publish jobs to a queue and process them
with long-running workers, without tying you to a single queue server.

[![CI](https://github.com/sshilko/backq/actions/workflows/ci.yml/badge.svg)](https://github.com/sshilko/backq/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/packagist/v/sshilko/backq)](https://packagist.org/packages/sshilko/backq)
[![Downloads](https://img.shields.io/packagist/dt/sshilko/backq)](https://packagist.org/packages/sshilko/backq)
[![License](https://img.shields.io/github/license/sshilko/backq)](LICENSE)

[![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)](composer.json)
[![Coding standard](https://img.shields.io/badge/coding%20standard-PSR--12-8892BF)](build/phpcs-ruleset.xml)
[![PHPStan](https://img.shields.io/badge/PHPStan-0%20errors-brightgreen)](https://github.com/sshilko/backq/actions/workflows/ci.yml)
[![Psalm](https://img.shields.io/badge/Psalm-0%20errors%20%7C%20level%203-4BA148)](https://github.com/sshilko/backq/actions/workflows/ci.yml)
[![Tests](https://img.shields.io/badge/tests-199%20passing-brightgreen)](https://github.com/sshilko/backq/actions/workflows/ci.yml)

![BackQ](https://github.com/sshilko/backq/raw/master/example/example.jpg "Publish jobs with publishers, process them with workers")

---

## Overview

BackQ separates job **publication** from job **processing**:

- **Publishers** enqueue jobs onto a queue and describe *what* to run.
- **Workers** are long-running processes that pull jobs off the queue and execute
  them — as OS processes, asynchronous PSR-7 HTTP requests, or closure payloads.

Two queue backends are production-ready: **Beanstalkd** and **Redis**. A third,
`MySql`, keeps the queue in a MySQL table instead of a queue server.

### How a job travels

Every job follows the same path, whatever the backend is. The `Message` describes
*what* to run, the publisher hands it to the queue, and the worker executes it on the
other end:

```
  ┌──────────┐   ┌───────────┐   ┌───────────────────┐
  │  Message │──▶│ Publisher │──▶│ Publisher adapter │   PUBLISH SIDE
  │  what to │   │   wraps   │   │  putTask()        │   (write path)
  │   run    │   │  message  │   │  (write side)     │
  └──────────┘   └───────────┘   └─────────┬─────────┘
                                           │
                                           │  the queue server owns the
                                           │  payload until a worker takes it
                                 ┌─────────▼─────────┐
                                 │   Queue server    │   QUEUE SERVER BACKEND
                                 │ Redis | Beanstalkd│
                                 │ MySql            │
                                 └─────────┬─────────┘
                                           │
                                           ▼
  ┌──────────┐◀──────────────────┌─────────▼─────────┐
  │  Worker  │                   │  Worker adapter   │   CONSUME SIDE
  │ executes │                   │  (read side)      │   (read path)
  │ the job  │                   └───────────────────┘
  └────┬─────┘
       │ afterWorkSuccess() / afterWorkFailed()  ──▶  back to the queue server
```

Read it as two halves that meet at the queue server:

1. **Publish path** — `Message` → `Publisher` → `Publisher Adapter` → `putTask()`.
   The publisher adapter is the only part that speaks the backend's write protocol.
2. **Consume path** — `Queue server backend` → `Worker Adapter` (`connect()`,
   `pickTask()`) → `Worker` executes the job → `afterWorkSuccess()` or
   `afterWorkFailed()` tells the backend the outcome.

`AbstractAdapter` is the contract that both halves implement, so a worker written
against Beanstalkd runs unchanged on Redis, and vice versa. It is also split into
two roles — `QueueConsumer` for the consume side, `QueueProducer` for the publish
side — so a component that only publishes depends on four methods instead of
eleven (see [Building an adapter](#building-an-adapter)).
- Queue backends are implemented independently

## Benefits

- **One worker, two production-ready queue backends.** Beanstalkd and Redis are
  interchangeable behind a common adapter contract — swap the backend without
  touching your workers.
- **Asynchronous HTTP via Guzzle.** Execute any
  [PSR-7 `Request`](https://www.php-fig.org/psr/psr-7/) in the background,
  with full response/failure handling in the worker.
- **Any OS process via `symfony/process`.** Run arbitrary command lines from the
  queue and let the worker manage lifecycle, output and exit codes.
- **Closures straight from the queue.** The `Closure` worker runs PHP callables with
  [opis/closure](https://github.com/opis/closure) serialization.
  Allowing to schedule any code as [php/closure](https://www.php.net/manual/en/class.closure.php)
- **Re-publish through a proxy worker.** The `Serialized` worker wraps a message
  from one publisher, `unserialize()`s it and publishes it again through a second
  publisher on another adapter — a thin wrapper for routing and re-queueing jobs
  (see [The `Serialized` worker](#the-serialized-worker-a-proxy-worker)).
- **Production-minded workers.** Built-in `setRestartThreshold` (terminate after a
  maximum number of job cycles) and `setIdleTimeout` (terminate after prolonged
  idleness) let supervisors restart workers and rotate them cleanly.
- **Extendable by design.** Write your own `Worker`, `Publisher` or `Message` and
  reuse the existing adapters out of the box.
- **Quality gate enforced by CI.** Every pull request is validated inside the
  dockerized PHP 8.3 app: PHPUnit (including live Redis integration) plus a
  six-tool static-analysis stack — PHPStan, Psalm
  (`errorLevel=3`, suppressions disabled), Phan, PHPMD, PDepend and PHP_CodeSniffer
  (PSR-12).

## Requirements

- PHP **>= 8.3**
- `ext-redis`, and one of the supported queue servers (see below)
- Composer

## Installation

```bash
composer require sshilko/backq:^5.0
```

## Quick start — Redis adapter with the `process` worker

```bash
# clone the repository and install dependencies
git clone https://github.com/sshilko/backq && cd backq
composer install

# launch a local redis server
docker run -d --name=example-backq-redis --network=host redis

# enqueue a job (publish)
php example/publishers/process/redis.php
# Published process message via redis adapter as ID=xoOgPKcS9bIDVXSaLYH9aLB22gzzptRo

# process the job (work)
php example/workers/process/redis.php

# verify the job executed (the example worker appends to /tmp/test)
cat /tmp/test

docker stop example-backq-redis
```

The examples autoload the library from the checkout's `vendor/autoload.php`, so run
them from a clone of this repository. In your own project, depend on
`sshilko/backq` and build workers against the `BackQ\` namespace directly.

## Supported queue servers

| Adapter | Status | Server |
|---|---|---|
| `Beanstalk` | stable | [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt) |
| `Redis` | stable | [Redis](https://redis.io) |
| `MySql` | stable | [MySQL](https://www.mysql.com) (needs `ext-mysqli`) |

## Workers and adapters

### Worker compatibility

| Adapter / Worker | [Process](http://symfony.com/doc/current/components/process.html) | [Guzzle](https://www.php-fig.org/psr/psr-7/) | Serialized | [Closure](https://github.com/opis/closure) |
|---|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/) — stable | ✓ | ✓ | ✓ | ✓ |
| [Redis](https://redis.io) — stable | ✓ | ✓ | ✓ | ✓ |
| [MySQL](https://www.mysql.com) — stable | ✓ | ✓ | ✓ | ✓ |

### Adapter features

| Adapter / Feature | `ping` | `hasWorkers` | `setWorkTimeout` |
|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/) — stable | ✓ | ✓ | ✓ |
| [Redis](https://redis.io) — stable | ✓ | ✓ | ✓ |
| [MySQL](https://www.mysql.com) — stable | ✓ | * | * |

`*` — unsupported/partial:
- `MySql::hasWorkers()` reports no workers without checking,
- `MySql::setWorkTimeout()` is accepted but not applied — idle timeouts are a
  worker concern, and the MySQL queue is shared through the table.

On Redis, `hasWorkers()` is answered from a per-queue lease registry in Redis, so
it spans processes and hosts: a worker announces itself when it binds read, renews
while it works, and gives the lease back when it disconnects. A worker that is
killed leaves a lease behind, and the lease expires on its own.

What it measures is *a worker was seen on this queue within the last `workerTtl`
seconds*, where `workerTtl` is a `RedisConfig` field defaulting to `300`. Set it
above your longest job: a worker running a job longer than `workerTtl` is not
reported, and the answer is `false` (publish anyway) rather than a wrong `true`. A
lease that cannot be written — a Redis ACL that permits the queue commands and not
`ZADD` — is logged and ignored, and the worker keeps working.

The registry lives in `backq:workers:{queue}`, under the configured key prefix if
you set one. `Illuminate\Queue\RedisQueue::clear()` does not remove it, and a
`KEYS` dump will show it; an entry there means a lease, not a job.

### Building an adapter

Every adapter takes a PSR-3 `LoggerInterface` first and a value object second, and
that is where its configuration lives:

```php
use BackQ\Adapter\Beanstalk\Connection;
use BackQ\Adapter\MySql\JobConfig;
use BackQ\Adapter\MySql as MySqlAdapter;
use BackQ\Adapter\PersistentBeanstalk;
use BackQ\Adapter\Redis;
use BackQ\Adapter\Redis\RedisConfig;

$beanstalk = new PersistentBeanstalk($logger);
$beanstalk->connect(new Connection(host: 'beanstalkd', timeout: 5));

$redis     = new Redis($logger, new RedisConfig(host: 'redis', prefix: 'app:'));
$mysql     = new MySqlAdapter($db, new JobConfig(table: 'backq_jobs'), $logger);
```

`RedisConfig` and `Connection` validate their own fields and throw
`InvalidArgumentException` naming the offending one, at construction rather than
against the server. The MySQL adapter is handed an established `mysqli` and never
opens or closes it — give `JobConfig` a `connectionProvider` closure if a worker
should be able to replace a link that has dropped:

```php
$config = new JobConfig(
    table: 'backq_jobs',
    connectionProvider: static fn (): mysqli => new mysqli('127.0.0.1', 'backq', 'secret', 'backq_jobs'),
);
```

`AbstractAdapter` splits into two roles — `QueueConsumer` for a worker and
`QueueProducer` for a publisher — so a component that only ever publishes depends
on four methods instead of eleven. `AbstractAdapter` implements both, so any
existing subclass is both roles already. Narrowing `AbstractWorker` and
`AbstractPublisher` to the role interfaces is deferred to 6.0.

**Failure policy.** A transport or storage failure is *returned*, never thrown:
`putTask()` hands back a `Throwable`, and `ping()` / `pickTask()` / the two
`afterWork*()` methods report `false` and log one PSR-3 record carrying
`['exception' => $e]`. An invalid *argument* still throws. `false` from an
acknowledgement means the backend did not confirm the job — the worker treats
that as `Worker failed to acknowledge job result` and ends the cycle, so a job
lost to an expiry or a competing consumer is no longer acknowledged as handled.

### Worker controls

Every worker takes the adapter and, optionally, the seconds a work cycle may take:

```php
$worker = new Serialized($adapter, workTimeout: 30);
```

- `workTimeout` — constructor argument, 60 seconds by default
  (`AbstractWorker::DEFAULT_WORK_TIMEOUT`). It is handed to the adapter on start, so
  a queue with blocking picks waits instead of spinning. `null` polls without
  blocking. `setWorkTimeout()` still sets the same value and is **deprecated**.
- `setRestartThreshold` — limit the maximum number of job cycles, then terminate.
- `setIdleTimeout` — limit maximum idle time, then terminate.

A work cycle may not outlast the idle timeout, the worker has to reach its idle check
while the deadline is still ahead of it. A `workTimeout` that reaches `setIdleTimeout()`
is therefore lowered to one second below it, and both the adapter and the work loop use
the lowered value. The configured number is left alone, and the worker logs what it
lowered and to what.

## The `Serialized` worker (a proxy worker)

`Serialized` is not a job type — it is a **proxy**. It carries another publisher's
message, `unserialize()`s it and publishes it again through a *different* publisher,
usually on a different adapter:

```
  publisher A  ──▶ [ queue 1 ] ──▶ Serialized worker ──▶ publisher B ──▶ [ queue 2 ]
   (e.g. Redis)                     (php serialize())                (e.g. Beanstalkd)
```

- `BackQ\Publisher\Serialized` and `BackQ\Worker\Serialized` wrap the message with
  `php serialize()` and unwrap it again.
- The wrapped message is published as-is, so the target publisher decides what runs
  and on which queue.
- Typical uses: re-publishing a job onto a different backend, and deferring a job
  without holding a worker or a connection open while it waits.

Because the target publisher is a normal publisher, everything the normal workers do
still works downstream — `Serialized` only handles the hand-off.

## Releases and version history

The latest **published** release on Packagist is `3.0.2` (2022-01-12).

The next
major — **v5** — is the upcoming release; it is not tagged yet.

**v5 is a hardened rewrite**: the code base moved from PHP 7.4 to **PHP 8.3** and
carries backward-incompatible changes (removed deprecated and dead API, typed
adapter contract, stricter error handling, `#[Override]` attributes, hardened
protocol/stream reads). **v4 was never released** — it was an intermediate
PHP 8.1 modernization step, superseded by v5 and skipped on the way to Packagist.

See [UPGRADING](UPGRADING) for the full list of 4.x → 5.x breaking changes: PHP >= 8.3
requirement, `hasWorkers()` narrowed to `bool`, typed `AbstractAdapter` parameters,
and removal of deprecated adapter aliases and dead API.

| Series | Span | First release | Latest release |
|---|---|---|---|
| **v1** | 1.0.0 → 1.9.13 | 2014-09-25 (`1.0.0`) | 2019-09-19 (`1.9.13`) |
| **v2** | 2.0.6 → 2.0.7 | 2019-09-19 (`2.0.6`) | 2019-09-24 (`2.0.7`) |
| **v3** (latest published) | 3.0.2 | — | 2022-01-12 (`3.0.2`) |
| **v4** (never released) | — | — | superseded by v5 |
| **v5** (upcoming) | — | — | PHP 7.4 → 8.3 hardened rewrite |

<details>
<summary>Complete tag list (all available release tags)</summary>

**v1**

| Tag | Date | Tag | Date |
|---|---|---|---|
| `1.0.0` | 2014-09-25 | `1.1.0` | 2016-03-28 |
| `1.0.1` | 2014-10-07 | `1.1.1` | 2016-03-28 |
| `1.0.2` | 2014-10-07 | `1.1.2` | 2016-03-28 |
| `1.0.3` | 2014-11-04 | `1.2.0` | 2017-01-11 |
| `1.0.4` | 2015-01-30 | `1.2.1` | 2017-09-06 |
| `1.0.5` | 2015-04-07 | `1.3.0` | 2017-12-12 |
| `1.0.6` | 2015-04-16 | `1.3.1` | 2018-01-05 |
| `1.0.7` | 2015-04-23 | `1.9.13` | 2019-09-19 |
| `1.0.8` | 2015-12-11 | | |
| `1.0.9` | 2015-12-11 | | |
| `1.0.10` | 2015-12-12 | | |
| `1.0.11` | 2016-02-10 | | |

**v2**

| Tag | Date |
|---|---|
| `2.0.6` | 2019-09-19 |
| `2.0.7` | 2019-09-24 |

**v3**

| Tag | Date |
|---|---|
| `3.0.2` | 2022-01-12 |

</details>

## Testing

The project ships a PHPUnit suite under `tests/` covering adapters, workers,
publishers and messages.

```bash
# start / stop the dev stack: the app-php83 container plus redis
composer app-up
composer app-down

# dockerized — brings the stack up, checks every class file loads, runs the suite
composer app-tests
```

`composer app-code-quality` runs the full static-analysis stack (phpcs, phpstan,
psalm, phan, phpmd, pdepend). It is a **container-side** script, so invoke it through the
stack rather than on the host:

```bash
docker compose -f build/docker-compose.yaml exec -T app-php83 composer app-code-quality
```

`composer app-tests-local` runs the suite against whatever PHP is on the host. It needs
`mbstring` and a reachable Redis; `phpunit.xml` sets `failOnSkipped="true"`, so an
unreachable service fails the run rather than skipping. Point the tests at the port
`build/docker-compose.yaml` publishes on the host:

```bash
BACKQ_REDIS_PORT=16379 composer app-tests-local
```

The same suite runs on every pull request in
[GitHub Actions](https://github.com/sshilko/backq/actions/workflows/ci.yml).

## Examples

See the [`example/`](https://github.com/sshilko/backq/tree/master/example) folder
for usage examples of the stable adapters:

- `example/adapter/<name>/{push,pop}.php` — raw queue adapters (Beanstalkd, Redis)
- `example/publishers/<type>[/<adapter>].php` + `example/workers/<type>[/<adapter>].php` —
  runnable publisher/worker pairs for the `process`, `closure`, `guzzle` and
  `serialized` worker types
- `example/publishers/lib/` — writing your own publisher on top of an adapter
- `example/http/server.php` — a minimal `php -S` router that the Guzzle examples
  point at, so they run without any external HTTP service

The Redis examples run against the dockerized service from
`build/docker-compose.yaml`. The `MySql` adapter has no example: it needs an
established `mysqli` link and a job table, so it is exercised by the test suite
instead — see `UPGRADING` for its constructor.

## License

MIT — see [LICENSE](LICENSE).

Copyright 2013-2026 Sergei Shilko

Copyright 2016-2019 Carolina Alarcon
