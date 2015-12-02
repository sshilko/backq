backq
=====

Perform tasks with workers &amp; publishers (queue)

* push notifications (Apnsd) processing 
* asynchronous process executon via [proc_open](http://php.net/manual/en/function.proc-open.php) implemented by [symfony/process](http://symfony.com/doc/current/components/process.html) component

#### Requirements

* davidpersson/beanstalk [library](https://github.com/davidpersson/beanstalk) for Beanstalkd 
* PHP 5.5 ([generators](http://php.net/manual/en/language.generators.overview.php) & coroutines)

#### Push notifications requirements

* [ApnsPHP](https://github.com/duccio/ApnsPHP/) or [symfony/process](http://symfony.com/doc/current/components/process.html) component

#### Process dispatch requirements
 
* [symfony/process](http://symfony.com/doc/current/components/process.html) component
  
#### Licence
[MIT](http://opensource.org/licenses/MIT)

#### Review

[Blog post about sending Apple push notifications](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

#### Basic Usage (push notifications)

Provided

* Adapter for Beanstalkd

```
//by default connects to 127.0.0.1:11300
$adapter = new \BackQ\Adapter\Beanstalk;
```

* Worker (can have multiple per same queue) that dispatches messages

```
$log = 'somepath/log.txt';
$ca  = 'somepath/entrust_2048_ca.cer';
$pem = 'somepath/apnscertificate.pem';
$env = \ApnsPHP_Abstract::ENVIRONMENT_SANDBOX;

$worker = new \BackQ\Worker\Apnsd(new \BackQ\Adapter\Beanstalk);

$worker->setLogger(new \BackQ\Logger($log));
$worker->setRootCertificationAuthority($ca);
$worker->setCertificate($pem);
$worker->setEnvironment($env);
$worker->setQueueName('apnsd');
//$worker->toggleDebug(true);

//enable for PHP 5.5.23,5.5.24 & 5.6.7,5.6.8 (does not honor the stream_set_timeout())
//see https://bugs.php.net/bug.php?id=69393
//$worker->connectTimeout = 2;

$worker->run();
```

* Publisher that pushes new messages into Beanstalkd queue

```
//array of [\BackQ\Message\ApnsPHP or ApnsPHP_Message_Custom or ApnsPHP_Message]
$messages  = array();
$publisher = \BackQ\Publisher\Apnsd::getInstance(new \BackQ\Adapter\Beanstalk);
$publisher->setQueueName('apnsd');

/**
 * Give 4 seconds to dispatch the message (time to run)
 * (wait 4 seconds for worker response on job status, see Beanstalkd protocol for details)
 */
$params = array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => 4);

/**
 * Delay sending push by 10 seconds
 */
$params[\BackQ\Adapter\Beanstalk::PARAM_READYWAIT] = 10;

//try connecting to Beanstalkd and ensure there are workers waiting for a job
if ($publisher->start() && $publisher->hasWorkers()) {
    for ($i=0; $i < count($messages); $i++) {
        $result = $publisher->publish($messages[$i], $params);
        if ($result > 0) {
            //successfully added to dispatch queue
        } else {
            //try something else
        }
    }
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







