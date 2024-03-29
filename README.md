BackQ - Component
=================

Background **queue processing** - publish tasks and process with workers, simplified.

* Sending [APNS](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html#//apple_ref/doc/uid/TP40008194-CH100-SW9) push notifications (Legacy API)
* Sending [FCM](https://firebase.google.com/docs/cloud-messaging) push notifications to Android (GCM/FCM)
* Sending [AWS SNS](https://aws.amazon.com/sns/) push notifications via AWS SNS arn's
* Executing [Psr7\Request](https://www.php-fig.org/psr/psr-7/) asynchronously via Guzzle
* Executing **any** processes with [symfony/process](http://symfony.com/doc/current/components/process.html)
* [Long delay scheduling](https://aws.amazon.com/blogs/aws/new-manage-dynamodb-items-using-time-to-live-ttl/) via DynamoSQS Adapter and Serialized worker, for reliable long-term scheduled jobs 
* Extendable - write your own worker and use existing adapters out of the box ...

#### Installation

```
#composer self-update && composer clear-cache && composer diagnose
composer require sshilko/backq:^3.0
```

#### Example with Redis adapter and `process` worker
```
#launch local redis
docker run -d --name=example-backq-redis --network=host redis

#install library in any folder for testing
mkdir /tmp/example && cd /tmp/example
composer require sshilko/backq:^3.0

#post job to queue (schedule)
cd vendor/sshilko/backq/example/publishers/process && php redis.php && cd /tmp/example

#[debug] connect
#[debug] _connect
#[debug] putTask
#[debug] putTask is connected and ready to: write
#[debug] putTask pushed task without delay xoOgPKcS9bIDVXSaLYH9aLB22gzzptRo
#[debug] putTask return 'xoOgPKcS9bIDVXSaLYH9aLB22gzzptRo'
#Published process message via redis adapter as ID=xoOgPKcS9bIDVXSaLYH9aLB22gzzptRo

#fetch job from queue (work)
cd vendor/sshilko/backq/example/workers/process && php redis.php && cd /tmp/example

#[debug] connect
#[debug] _connect
#[debug] pickTask
#[debug] pickTask blocking for 5 seconds until get a job
#[debug] pickTask reserved a job nOgykJV81g969yw2wRMF94V9KiIeKN4P
#[debug] afterWorkSuccess
#[debug] afterWorkSuccess currently 1 reserved job(s)
#[debug] afterWorkSuccess releasing completed nOgykJV81g969yw2wRMF94V9KiIeKN4P job
#[debug] Disconnecting
#[debug] Disconnecting, previously connected
#[debug] Disconnecting, state detected
#[debug] Disconnecting, state detected, queue is connected
#[debug] Disconnecting, state 0 jobs reserved and not finalized
#[debug] Disconnecting, state detected, disconnecting queue manager
#[debug] Disconnecting, successful

#verify job executed (example process worker does echo $( date +%s ) >> /tmp/test) 
cat /tmp/test

docker stop example-backq-redis
```

#### Supported queue servers

* [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt)
* [Redis](https://redis.io) 
* [NSQ](https://nsq.io) 
* [DynamoDB](https://aws.amazon.com/dynamodb/) [SQS](https://aws.amazon.com/sqs/) [Lambda](https://aws.amazon.com/lambda/) for DynamoSQS adapter

#### Features

Workers compatibility with adapters

| Adapter / Worker  |[FCM](https://firebase.google.com/docs/cloud-messaging)|[APNS](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html#//apple_ref/doc/uid/TP40008194-CH100-SW9)|[Process](http://symfony.com/doc/current/components/process.html)|[Guzzle](https://www.php-fig.org/psr/psr-7/)|Serialized|[AWS SNS](https://aws.amazon.com/sns/)|[Closure](https://github.com/opis/closure)|
|----|---|---|---|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/)   | +  | +  | +  | +  | +  | +  | + |
| [Redis](https://redis.io)        | +  | +  | +  | +  | ?  | +  | + |
| [NSQ](https://nsq.io/)          | +  | +  | +  | +  | ?  | +  | ? |
| [DynamoSQS](https://aws.amazon.com/)    | +  | +  | +  | +  | +  | ?  | + |

Adapter implemented features

| Adapter / Feature  | ping  | hasWorkers  | setWorkTimeout |
|---|---|---|---|
| [Beanstalkd](https://beanstalkd.github.io/)  | + | +  | + 
| [Redis](https://redis.io) | + | - | + 
| [NSQ](https://nsq.io/) | + |  - | * 
| [DynamoSQS](https://aws.amazon.com/) | - | - | + 

Worker available features

- `setRestartThreshold` (limit max number of jobs cycles, then terminate)
- `setIdleTimeout` (limit max idle time, then terminating)

TLDR

![Backq](https://github.com/sshilko/backq/raw/master/example/example.jpg "Background tasks with workers and publishers via queues")

See [/example](https://github.com/sshilko/backq/tree/master/example) folder for usage examples

#### Old version 1 detailed review

[Blog post about sending Apple push notifications](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

#### Licence
MIT

Copyright 2013-2022 Sergei Shilko
Copyright 2016-2019 Carolina Alarcon


