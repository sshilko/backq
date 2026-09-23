BackQ - Component
=================

Background **queue processing** - publish tasks and process with workers, simplified.

* Sending [AWS SNS](https://aws.amazon.com/sns/) push notifications via AWS SNS arn's
* Executing [Psr7\Request](https://www.php-fig.org/psr/psr-7/) asynchronously via Guzzle
* Executing **any** processes with [symfony/process](http://symfony.com/doc/current/components/process.html)
* [Long delay scheduling](https://aws.amazon.com/blogs/aws/new-manage-dynamodb-items-using-time-to-live-ttl/) via the DynamoSQS adapter and the serialized worker, for reliable long-term scheduled jobs
* Extendable - write your own worker and use existing adapters out of the box ...

Requires **PHP >= 8.3**.

#### Installation

```
composer require sshilko/backq:^5.0
```

#### Testing

The project ships a PHPUnit suite under `tests/` covering adapters, workers, publishers
and messages.

```
# dockerized (redis + nsq services from build/docker-compose.yaml, needs Docker)
composer app-tests

# host-side (Redis/NSQ integration tests skip when the services are unreachable)
composer app-tests-local
```

`composer app-code-quality` runs the full static-analysis stack (phpcs, phpstan, psalm,
phan, phpmd, pdepend) inside the dockerized app.

#### Example with Redis adapter and `process` worker

```
# clone the repo and install dependencies
git clone https://github.com/sshilko/backq && cd backq
composer install

# launch local redis
docker run -d --name=example-backq-redis --network=host redis

# post a job to the queue (schedule)
php example/publishers/process/redis.php
# Published process message via redis adapter as ID=xoOgPKcS9bIDVXSaLYH9aLB22gzzptRo

# fetch the job from the queue (work)
php example/workers/process/redis.php

# verify the job executed (the example process worker appends to /tmp/test)
cat /tmp/test

docker stop example-backq-redis
```

The examples autoload the library from the repo-root `vendor/autoload.php`, so run them
from a checkout of this repository. In your own project, depend on
`composer require sshilko/backq:^5.0` and build workers against `BackQ\` directly.

#### Supported queue servers

* [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt)
* [Redis](https://redis.io)
* [NSQ](https://nsq.io)
* [DynamoDB](https://aws.amazon.com/dynamodb/) [SQS](https://aws.amazon.com/sqs/) [Lambda](https://aws.amazon.com/lambda/) for the DynamoSQS adapter

#### Features

Workers compatibility with adapters

| Adapter / Worker  |[Process](http://symfony.com/doc/current/components/process.html)|[Guzzle](https://www.php-fig.org/psr/psr-7/)|Serialized|[AWS SNS](https://aws.amazon.com/sns/)|[Closure](https://github.com/opis/closure)|
|----|---|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/)   | +  | +  | +  | +  | + |
| [Redis](https://redis.io)        | +  | +  | ?  | +  | + |
| [NSQ](https://nsq.io/)          | +  | +  | ?  | +  | ? |
| [DynamoSQS](https://aws.amazon.com/)    | +  | +  | +  | ?  | + |

Adapter implemented features

| Adapter / Feature  | ping  | hasWorkers  | setWorkTimeout |
|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/)  | + | +  | +
| [Redis](https://redis.io) | + | * | +
| [NSQ](https://nsq.io/) | + |  * | *
| [DynamoSQS](https://aws.amazon.com/) | * | * | +

`*` - unsupported/partial: `NSQ::ping()` only reflects an already-open connection;
`DynamoSQS::ping()` always returns `true`. `hasWorkers()` is a stub on `Redis`,
`Nsq` and `DynamoSQS` that always reports `false` (Beanstalkd implements it against
real stats). `NSQ::setWorkTimeout()` is accepted but not applied by the server
protocol.

Worker available features

- `setRestartThreshold` (limit max number of jobs cycles, then terminate)
- `setIdleTimeout` (limit max idle time, then terminating)

> Migrating from 4.x? See [UPGRADING](UPGRADING) for 5.x backward-incompatible changes:
> PHP >= 8.3 requirement, `hasWorkers()` narrowed to `bool`, and the removal of
> deprecated adapter aliases and dead API.

TLDR

![Backq](https://github.com/sshilko/backq/raw/master/example/example.jpg "Background tasks with workers and publishers via queues")

#### Detailed review

See the [/example](https://github.com/sshilko/backq/tree/master/example) folder for usage examples.

#### Licence
MIT

Copyright 2013-2026 Sergei Shilko
Copyright 2016-2019 Carolina Alarcon