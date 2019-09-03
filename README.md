backq
=====

Background tasks with workers &amp; publishers via queues

* Sending [APNS](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html#//apple_ref/doc/uid/TP40008194-CH100-SW9) Push notifications sending (Legacy API)
* Executing processes via [proc_open](http://php.net/manual/en/function.proc-open.php) implemented by [symfony/process](http://symfony.com/doc/current/components/process.html)
* Sending [FCM](https://firebase.google.com/docs/cloud-messaging) Push notifications for Android devices (GCM/FCM)
* Sending [AWS SNS](https://aws.amazon.com/sns/) Push notifications via AWS SNS ARN's
* Executing [Psr7\Request](https://www.php-fig.org/psr/psr-7/) requests asynchronously via Guzzle worker
* Long delay scheduling via DynamoSQS Adapter and Serialized worker, for reliable long-term scheduled jobs 

#### Installation
```
#composer self-update && composer clear-cache && composer diagnose
composer require viveme/backq:^2.0
```

#### Requirements (>=2.0.0)

* Required PHP 7

#### Requirements varies on queue adapter

* [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt) davidpersson/beanstalk [library](https://github.com/davidpersson/beanstalk) Simple & Fast work queue 
* [Redis](https://redis.io) Simple & Fast work queue
* [DynamoDB](https://aws.amazon.com/dynamodb/) [SQS](https://aws.amazon.com/sqs/) [Lambda](https://aws.amazon.com/lambda/) for DynamoSQS adapter

#### Licence
MIT

Copyright 2019 Sergei Shilko

#### API / Basic Usage / APNS push notifications

You are able to use any adapter with any publisher/worker i.e.

|   |FCM|APNS|Process|Guzzle|Serialized|
|----|---|---|---|---|---|
| Adapter Beanstalkd   | +  | +  | +  | +  | ?  |
| Adapter Redis        | +  | +  | +  | +  | ?  |
| Adapter NSQ          | +  | +  | +  | +  | ?  |
| Adapter DynamoSQS    | +  | +  | +  | +  | +  |

Adapters typically support
* setRestartThreshold (after how many processed jobs the worker will terminate)
* setIdleTimeout (after how many seconds idling the worker will terminate)
* supports delaying job execution (in seconds)
* Beanstalkd adapter supports [TTR](https://github.com/beanstalkd/beanstalkd/wiki/FAQ)

Better adapter documentation/interfaces coming soon. `@todo`

See `example` folder for up to date examples

#### Version 1 detailed review

[Blog post about sending Apple push notifications](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

