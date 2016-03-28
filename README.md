backq
=====

[![Latest Stable Version](https://poser.pugx.org/sshilko/backq/v/stable)](https://packagist.org/packages/sshilko/backq)
[![License](https://poser.pugx.org/sshilko/backq/license)](https://packagist.org/packages/sshilko/backq)

Perform tasks with workers &amp; publishers (queues)

* [APNS](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html#//apple_ref/doc/uid/TP40008194-CH100-SW9) Push notifications sending
* asynchronous process executon via [proc_open](http://php.net/manual/en/function.proc-open.php) implemented by [symfony/process](http://symfony.com/doc/current/components/process.html) component

#### Requirements

* Recommended [PHP >=7.0.4](https://launchpad.net/~ondrej/+archive/ubuntu/php)
* Required PHP 5.5 ([generators](http://php.net/manual/en/language.generators.overview.php) & coroutines)
* Simple & Fast work queue [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt) davidpersson/beanstalk [library](https://github.com/davidpersson/beanstalk)

#### Push notifications requirements

* Basic idea inspired by [ApnsPHP](https://packagist.org/packages/duccio/apns-php) but because package was not maintained for long time, own adapter was implemented (TLS support, payload size >= 256 in iOS8+, fwrite()/fread() error handlind, etc.).

#### Process dispatch requirements
 
* Processes are spawned via [symfony/process](http://symfony.com/doc/current/components/process.html) component
  
#### Licence
[MIT](http://opensource.org/licenses/MIT)

#### Review

[Blog post about sending Apple push notifications](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

#### API / Basic Usage / APNS push notifications

Initialize Queue adapter

* Adapter for Beanstalkd

```
//by default connects to 127.0.0.1:11300
$adapter = new \BackQ\Adapter\Beanstalk;
```

* Worker (can have multiple per same queue) that dispatches messages

```
$ca  = 'somepath/entrust_2048_ca.cer';
$pem = 'somepath/apnscertificate.pem';
$env = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;

$worker = new \BackQ\Worker\Apnsd($adapter);

/**
 * We can listen to custom queue and have multiple queues & multiple workers per queue
 * @optional
 */
$worker->setQueueName($worker->getQueueName() . 'myQueueName1');

/**
 * The longer we wait the less changes we send push into closed socket
 * @optional
 */
$worker->socketSelectTimeout = \BackQ\Worker\Apnsd::SENDSPEED_TIMEOUT_RECOMMENDED;

$worker->setLogger(new \BackQ\Logger('somepath/logfile.txt'));

$worker->setRootCertificationAuthority($ca);
$worker->setCertificate($pem);
$worker->setEnvironment($env);

/**
 * Output basic debug
 */
//$worker->toggleDebug(true);

/**
 * Quit the worker after processing 1000 pushes
 * @optional
 */
$worker->setRestartThreshold(1000);

/**
 * Quit the worker if no jobs received for 600 seconds
 * @optional
 */
$worker->setIdleTimeout(600);

/**
 * Set stream_set_timeout() for stream_socket_client()
 * @optional
 */
$worker->readWriteTimeout = 5;

/**
 * Workaround for
 * PHP 5.5.23,5.5.24 & 5.6.7,5.6.8
 * does not honor the stream_set_timeout()
 * @see https://bugs.php.net/bug.php?id=69393
 */
//$worker->connectTimeout = 2;

$worker->run();
```

* Publisher pushes new messages into Beanstalkd queue

```
$publisher = \BackQ\Publisher\Apnsd::getInstance(new \BackQ\Adapter\Beanstalk);

/**
 * We can publish to custom queue
 * @optional
 */
$publisher->setQueueName($worker->getQueueName() . 'myQueueName1');

/**
 * Give 4 seconds to dispatch the message (time to run)
 * Unless worker reports successfuly that job is done, the job is put back into "ready" state
 * @optional
 */
$params = array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => 4);

/**
 * Delay sending push by 10 seconds
 * @optional
 */
$params[\BackQ\Adapter\Beanstalk::PARAM_READYWAIT] = 10;

/**
 * Ensure adapter can connect to Beanstalk & has workers WAITING for the job
 */
if ($publisher->start() && $publisher->hasWorkers()) {
    /**
     * Original ApnsPHP messages supported, recommended customized \BackQ\Message\ApnsPHP
     */
    $messages  = array();
    for ($i=0; $i < count($messages); $i++) {
        $result = $publisher->publish($messages[$i], $params);
        if ($result > 0) {
            /**
             * successfully added to dispatch queue
             */
        } else {
            /**
             * Fallback here
             */
        }
    }
} else {
  /**
   * Fallback here
   * 
   * + Unable to connect to Beanstalk 
   * + No workers currently waiting for job (all of them busy processing or none launched)
   */
}
```

#### Basic usage (processes)

A queue for the [symfony/process](http://symfony.com/doc/current/components/process.html) component usage.
A simple scheduler is done using Beanstalkd `delay` option for jobs. Or just dispatch projess jobs for async execution.

Worker daemon
```
$worker = new \BackQ\Worker\AProcess(new \BackQ\Adapter\Beanstalk);
$worker->run();
```

Publisher
```
$publisher = \BackQ\Publisher\Process::getInstance(new \BackQ\Adapter\Beanstalk);
if ($publisher->start() && $publisher->hasWorkers()) {
    $message = new \BackQ\Message\Process('echo $( date +%s ) >> /tmp/test');
    $result = $publisher->publish($message, array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => 3,
                                                  \BackQ\Adapter\Beanstalk::PARAM_READYWAIT => 4));
    if ($result > 0) {
        //Async process job added to queue
    } else {
        //fail
    }
}
```

#### Master-dev

Master dev since 1.0.11 release contains unsable code and has some experiments with GCM pusher over XMPP
via https://github.com/jaxl/JAXL Jaxl library, but unfortunatelly the design of Jaxl and bloking nature of beanstalkd (only with timeout)
are not best fit to each other, so with basic Jaxl library successfuly connecting to GCM, there is no nice way to bundle it as a working solution atm.
There isnt any cool XMPP/GCM workers unfortunatelly so i will look into writing one using [async](https://github.com/elazar/asynchronous-php) libraries


