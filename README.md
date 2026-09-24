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

- **Publishers** enqueue jobs onto a queue (Beanstalkd, Redis, NSQ, or
  DynamoDB + SQS) and describe *what* to run.
- **Workers** are long-running processes that pull jobs off the queue and execute
  them — as OS processes, asynchronous PSR-7 HTTP requests, push notifications, or
  serialized/closure payloads.

The `AbstractAdapter` contract in the middle means workers are written once and run
against any supported queue backend unchanged.

## Benefits

- **One worker, four queue backends.** Beanstalkd, Redis, NSQ and a
  DynamoDB + SQS hybrid are interchangeable behind a common adapter contract — swap
  the backend without touching your workers.
- **Push notifications via AWS SNS.** Send platform notifications through SNS
  endpoint ARNs with dedicated `Publish` / `Register` / `Remove` workers.
- **Asynchronous HTTP via Guzzle.** Execute any
  [PSR-7 `Request`](https://www.php-fig.org/psr/psr-7/) in the background,
  with full response/failure handling in the worker.
- **Any OS process via `symfony/process`.** Run arbitrary command lines from the
  queue and let the worker manage lifecycle, output and exit codes.
- **Reliable long-delay scheduling.** The DynamoSQS adapter with the serialized
  worker plans jobs far into the future using
  [DynamoDB Time-to-Live](https://aws.amazon.com/blogs/aws/new-manage-dynamodb-items-using-time-to-live-ttl/),
  without holding a clock or a connection open while you wait.
- **Production-minded workers.** Built-in `setRestartThreshold` (terminate after a
  maximum number of job cycles) and `setIdleTimeout` (terminate after prolonged
  idleness) let supervisors restart workers and rotate them cleanly.
- **Extendable by design.** Write your own `Worker`, `Publisher` or `Message` and
  reuse the existing adapters out of the box.
- **Quality gate enforced by CI.** Every pull request is validated inside the
  dockerized PHP 8.3 app: PHPUnit (199 tests, including live Redis and NSQ
  integration) plus a six-tool static-analysis stack — PHPStan, Psalm
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

| Adapter | Server |
|---|---|
| `Beanstalk` | [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt) |
| `Redis` | [Redis](https://redis.io) |
| `Nsq` | [NSQ](https://nsq.io) |
| `DynamoSQS` | [DynamoDB](https://aws.amazon.com/dynamodb/) + [SQS](https://aws.amazon.com/sqs/) (+ [Lambda](https://aws.amazon.com/lambda/) for scheduled stream processing) |

## Workers and adapters

### Worker compatibility

| Adapter / Worker | [Process](http://symfony.com/doc/current/components/process.html) | [Guzzle](https://www.php-fig.org/psr/psr-7/) | Serialized | [AWS SNS](https://aws.amazon.com/sns/) | [Closure](https://github.com/opis/closure) |
|---|---|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/) | ✓ | ✓ | ✓ | ✓ | ✓ |
| [Redis](https://redis.io) | ✓ | ✓ | ✓ | ✓ | ✓ |
| [NSQ](https://nsq.io/) | ✓ | ✓ | ✓ | ✓ | ✓ |
| [DynamoSQS](https://aws.amazon.com/) | ✓ | ✓ | ✓ | ? | ✓ |

### Adapter features

| Adapter / Feature | `ping` | `hasWorkers` | `setWorkTimeout` |
|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/) | ✓ | ✓ | ✓ |
| [Redis](https://redis.io) | ✓ | * | ✓ |
| [NSQ](https://nsq.io/) | ✓ | * | * |
| [DynamoSQS](https://aws.amazon.com/) | * | * | ✓ |

`*` — unsupported/partial: `NSQ::ping()` only reflects an already-open connection;
`DynamoSQS::ping()` always returns `true`. `hasWorkers()` is a stub on `Redis`,
`Nsq` and `DynamoSQS` that always reports `false` (Beanstalkd implements it against
real queue stats). `NSQ::setWorkTimeout()` is accepted but not applied by the
server protocol.

### Worker controls

- `setRestartThreshold` — limit the maximum number of job cycles, then terminate.
- `setIdleTimeout` — limit maximum idle time, then terminate.

## Releases and version history

The latest **published** release on Packagist is `3.0.2` (2022-01-12). Development
currently targets the next major — **v4** (PHP 8.1 modernization) and **v5**
(PHP 8.3 modernization) — which are tracked on dedicated branches and not yet
tagged. See [UPGRADING](UPGRADING) for the 4.x → 5.x backward-incompatible changes
(PHP >= 8.3 requirement, `hasWorkers()` narrowed to `bool`, and removal of
deprecated adapter aliases and dead API).

| Series | Span | First release | Latest release |
|---|---|---|---|
| **v1** | 1.0.0 → 1.9.13 | 2014-09-25 (`1.0.0`) | 2019-09-19 (`1.9.13`) |
| **v2** | 2.0.6 → 2.0.7 | 2019-09-19 (`2.0.6`) | 2019-09-24 (`2.0.7`) |
| **v3** (latest published) | 3.0.2 | — | 2022-01-12 (`3.0.2`) |

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
# dockerized — redis + nsq services from build/docker-compose.yaml (requires Docker)
composer app-tests

# host-side — Redis/NSQ integration tests skip when the services are unreachable
composer app-tests-local
```

`composer app-code-quality` runs the full static-analysis stack (phpcs, phpstan,
psalm, phan, phpmd, pdepend) inside the dockerized app. The same suite runs on
every pull request in [GitHub Actions](https://github.com/sshilko/backq/actions/workflows/ci.yml).

## Examples

See the [`example/`](https://github.com/sshilko/backq/tree/master/example) folder
for usage examples covering every adapter and worker combination:

- `example/adapter/<name>/{push,pop}.php` — raw queue adapters (Redis, NSQ,
  Beanstalkd)
- `example/publishers/<type>[/<adapter>].php` + `example/workers/<type>[/<adapter>].php` —
  runnable publisher/worker pairs for the `process`, `closure`, `guzzle` and
  `serialized` worker types against the Redis and NSQ adapters
- `example/http/server.php` — a minimal `php -S` router that the Guzzle examples
  point at, so they run without any external HTTP service
- `example/adapter/dynamosqs/` and `example/publishers/sns/` — AWS integrations
  (DynamoDB scheduled-stream processing, SNS push notifications)

The Redis and NSQ examples run against the dockerized services from
`build/docker-compose.yaml`.

## License

MIT — see [LICENSE](LICENSE).

Copyright 2013-2026 Sergei Shilko
Copyright 2016-2019 Carolina Alarcon