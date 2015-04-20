backq
=====

Background notifications processing with workers &amp; publisher

#### Requirements

* [ApnsPHP](https://github.com/duccio/ApnsPHP/)
* davidpersson/beanstalk [library](https://github.com/davidpersson/beanstalk) for Beanstalkd 
* PHP 5.5 ([generators](http://php.net/manual/en/language.generators.overview.php) & coroutines)

#### Licence
MIT

#### Review

[Blog post](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

#### Basic Usage

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

//enable for PHP 5.5.23 & 5.6.7 (does not honor the stream_set_timeout())
//$worker->connectTimeout = 2;

$worker->run();
```

* Publisher that pushes new messages into Beanstalkd queue

```
//array of [ApnsPHP_Message_Custom or ApnsPHP_Message]
$messages  = array();
$publisher = \BackQ\Publisher\Apnsd::getInstance(new \BackQ\Adapter\Beanstalk);
$publisher->setQueueName('apnsd');

//try connecting to Beanstalkd and ensure there are workers waiting for a job
if ($publisher->start() && $publisher->hasWorkers()) {
    //wait 3 seconds for worker response on job status, see Beanstalkd protocol for details
    $ttr = 3;
    for ($i=0; $i < count($messages); $i++) {
        $result = $publisher->publish($messages[$i], array(\BackQ\Adapter\Beanstalk::PARAM_JOBTTR => $ttr));
        if ($result > 0) {
            //successfull
        }
    }
}
```





