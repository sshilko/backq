BackQ (Background queues toolkit)
=====

Library that will help you to perform most of routing while executing asynchronous jobs with queues/workers/publishers

* Send iOS [APNS](https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/ApplePushService.html#//apple_ref/doc/uid/TP40008194-CH100-SW9) Push notifications
* Send Google's [FCM](https://firebase.google.com/docs/cloud-messaging/)
* Execute any processes asynchronously via [proc_open](http://php.net/manual/en/function.proc-open.php) implemented by [symfony/process](http://symfony.com/doc/current/components/process.html) component
* Dispatch [PSR-7](http://www.php-fig.org/psr/psr-7/) requests via [GuzzleHttp\Psr7\Request](https://github.com/guzzle/psr7) or compatible (typically any HTTP GET/POST/... requests) asynchronously
* [ZendFramework1](https://github.com/zf1/zend-http) adapter Zend_Http_Client_Adapter_Psr7 to convert into PSR-7 requests 

#### Installation
```
composer require sshilko/backq:^1.3
```

#### Requirements

* Recommended [PHP](http://php.net/ChangeLog-7.php#7.0.4) >= 7.0.4
* Required PHP 5.5 ([generators](http://php.net/manual/en/language.generators.overview.php) & coroutines)
* [Beanstalkd](https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt) queue adapter by [davidpersson/beanstalk](https://github.com/davidpersson/beanstalk/tree/v2.0.0)

#### Push notifications requirements

* Inspired by [ApnsPHP](https://packagist.org/packages/duccio/apns-php#v1.0.1) [original](https://code.google.com/archive/p/apns-php/) but package was not maintained for long time, own adapter was implemented (TLS support, payload size >= 256 in iOS8+, fwrite()/fread() error handlind, etc.).

#### Process dispatch requirements
 
* Processes are spawned via [symfony/process](http://symfony.com/doc/current/components/process.html) component
  
#### Licence
[MIT](http://opensource.org/licenses/MIT)

#### Review

[Blog post about sending Apple push notifications](http://moar.sshilko.com/2014/09/09/APNS-Workers/) 

#### API / Basic Usage / APNS push notifications (Legacy binary interface)

Initialize Queue adapter

* Adapter for Beanstalkd
```
    /**
     * Only Beanstalk is supported atm.
     * Recommended: default settings expect server at 127.0.0.1:11300 with non-persistent connection
     */ 
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
     * The longer we wait
     * the less chance that we will send push into closed socket (eof)
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

#### FCM FirebaseCloudMessage
Worker
```
    $fcmKey = 'my-key-here';    
    $worker = new \BackQ\Worker\Fcm(new \BackQ\Adapter\Beanstalk);
    $worker->setQueueName($worker->getQueueName() . 'fcm-1');
    $worker->setRestartThreshold(100);
    //$worker->toggleDebug(true);
    $worker->setIdleTimeout(600);
    $worker->setPusher(new \BackQ\Adapter\Fcm($fcmKey));
    $worker->run();
```

Publisher
```
    $publisher = \BackQ\Publisher\Fcm::getInstance(new \BackQ\Adapter\Beanstalk);
    $publisher->setQueueName($publisher->getQueueName() . 'fcm-1');
    if ($publisher->start() && $publisher->hasWorkers()) {
        /**
         * @var $msgs \BackQ\Message\Fcm[]|\Zend_Mobile_Push_Message_Gcm[]
         */
        foreach ($msgs as $msg) {
            if (!$publisher->publish($msg)) {
                //failover
            }
        }
    } else {
        //failover
    }
    $publisher->finish();
```

#### PSR-7 / Guzzle

Worker
```
    $worker = new \BackQ\Worker\Guzzle(new \BackQ\Adapter\Beanstalk);
    $worker->setQueueName($worker->getQueueName());
    $worker->setRestartThreshold(100);
    $worker->setIdleTimeout(360);
    //$worker->toggleDebug(true);
    $worker->run();
```

Publisher

```
    $adapter = new \Zend_Http_Client_Adapter_Psr7();
    $uri     = \Zend_Uri_Http::fromString(https://api.sparkpost.com/api/v1/transmissions');
    $client  = new \Zend_Http_Client($uri, ['adapter' => $adapter]);
    $client->setRawData(json_encode(['a' => 1, 'b' => 2], JSON_UNESCAPED_UNICODE | JSON_BIGINT_AS_STRING));
    $client->setHeaders(['Content-Type'  => 'application/json',
                         'Authorization' => 'SomeAuthKey',
                         'User-Agent'    => 'SecretAgent007']);
    $client->setMethod(\Zend_Http_Client::POST);
    $client->request();
    $stringRequest = $adapter->getRequestRaw();
    
    $adapter   = new \BackQ\Adapter\Beanstalk;
    if ($adapter->connect('127.0.0.1', '11300')) {
        $publisher = \BackQ\Publisher\Guzzle::getInstance($adapter);
        if ($publisher->start() && $publisher->hasWorkers()) {
            $result = $publisher->publish(new \BackQ\Message\Guzzle(null, $stringRequest));
            if ($result > 0) {
                //success
            }
        }
    }

```
