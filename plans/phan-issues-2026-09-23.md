# Phan Issues — 2026-09-23

Run inside container:
```bash
docker exec backq.php83 bash -c 'cd /app && php ./vendor/bin/phan --allow-polyfill-parser --color -k ./build/phan.php'
```

Results (14 issues):

```
src/Adapter/Beanstalk/Client.php:91   PhanPartialTypeMismatchArgument
src/Adapter/Beanstalk/Client.php:277  PhanPartialTypeMismatchArgumentInternal
src/Adapter/Nsq.php:500               PhanPartialTypeMismatchArgument
src/Adapter/Redis.php:125             PhanParamSignatureMismatch
src/Adapter/Redis.php:447             PhanTypeMismatchProperty
src/Adapter/Redis.php:570             PhanTypeMismatchArgument
src/Message/Guzzle.php:42             PhanPossiblyNullTypeMismatchProperty
src/Message/Guzzle.php:53             PhanPossiblyNullTypeArgument
src/Message/Process.php:28            PhanTypeMismatchDeclaredParamNullable
src/Publisher/AbstractPublisher.php:44  PhanTypeInstantiateAbstract
src/Publisher/AbstractPublisher.php:118 PhanPartialTypeMismatchReturn
src/Worker/Amazon/SNS/SnsClient.php:25 PhanParamSignatureMismatch
src/Worker/Amazon/SNS/SnsClient.php:39 PhanParamSignatureMismatch
src/Worker/Amazon/SNS/SnsClient.php:59 PhanParamSignatureMismatch
```

### Issue details

| File | Line | Issue | Detail |
|---|---|---|---|
| `src/Adapter/Beanstalk/Client.php` | 91 | `PhanPartialTypeMismatchArgument` | Argument 3 (`$connection_timeout`) is `$connectionTimeout` of type `int (value: 1)\|non-empty-mixed\|non-falsy-string\|non-zero-int\|true` but `\BackQ\Adapter\IO\StreamIO::__construct()` takes `float` (`non-falsy-string` is incompatible) defined at `src/Adapter/IO/StreamIO.php:86` |
| `src/Adapter/Beanstalk/Client.php` | 277 | `PhanPartialTypeMismatchArgumentInternal` | Argument 2 (`$code`) is `$ex->getCode()` of type `int\|string` but `\BackQ\Adapter\IO\Exception\RuntimeException::__construct()` takes `int` (`string` is incompatible) |
| `src/Adapter/Nsq.php` | 500 | `PhanPartialTypeMismatchArgument` | Argument 2 (`$body`) is `$this->config['auth']` of type `?false\|?int\|?non-empty-mixed\|?non-falsy-string\|?non-zero-int\|?string\|mixed` but `\BackQ\Adapter\Nsq::writeCommandWithBody()` takes `string` (`?non-zero-int` is incompatible) defined at `src/Adapter/Nsq.php:573` |
| `src/Adapter/Redis.php` | 125 | `PhanParamSignatureMismatch` | Declaration of function `render(\Illuminate\Http\Request $request, \Throwable $e) : void` should be compatible with function `render(\Illuminate\Http\Request $request, \Throwable $e) : \Symfony\Component\HttpFoundation\Response` defined in `vendor/illuminate/contracts/Debug/ExceptionHandler.php:36` |
| `src/Adapter/Redis.php` | 447 | `PhanTypeMismatchProperty` | Assigning (`$redisJob` as a field) of type `array<string,\Illuminate\Contracts\Queue\Job>` to property but `\BackQ\Adapter\Redis->reservedJobs` is `array<string,\Illuminate\Queue\Jobs\RedisJob>` |
| `src/Adapter/Redis.php` | 570 | `PhanTypeMismatchArgument` | Argument 1 (`$app`) is `$this->app` of type `\ArrayAccess\|\BackQ\Adapter\Redis\App\|\Illuminate\Container\Container\|\Illuminate\Contracts\Container\Container\|\Psr\Container\ContainerInterface` but `\BackQ\Adapter\Redis\Manager::__construct()` takes `\Illuminate\Contracts\Foundation\Application` defined at `vendor/illuminate/redis/RedisManager.php:69` |
| `src/Message/Guzzle.php` | 42 | `PhanPossiblyNullTypeMismatchProperty` | Assigning `$rawRequest` of type `null\|string` to property but `\BackQ\Message\Guzzle->request` is `string` (`null` is incompatible) |
| `src/Message/Guzzle.php` | 53 | `PhanPossiblyNullTypeArgument` | Argument 1 (`$message`) is `$this->request` of type `null\|string` but `\GuzzleHttp\Psr7\Message::parseRequest()` takes `string` (`null` is incompatible) defined at `vendor/guzzlehttp/psr7/src/Message.php:317` |
| `src/Message/Process.php` | 28 | `PhanTypeMismatchDeclaredParamNullable` | Doc-block of `$timeout` in `__construct` is phpdoc param type `float` which is not a permitted replacement of the nullable param type `?float` declared in the signature (`'?T'` should be documented as `'T\|null'` or `'?T'`) |
| `src/Publisher/AbstractPublisher.php` | 44 | `PhanTypeInstantiateAbstract` | Instantiation of abstract class `\BackQ\Publisher\AbstractPublisher` |
| `src/Publisher/AbstractPublisher.php` | 118 | `PhanPartialTypeMismatchReturn` | Returning `$this->adapter->putTask($this->serialize($serializable), $params)` of type `bool\|int\|string` but `publish()` is declared to return `false\|string` (`int` is incompatible) |
| `src/Worker/Amazon/SNS/SnsClient.php` | 25 | `PhanParamSignatureMismatch` | Declaration of function `publish(array $data) : mixed` should be compatible with function `publish(array $args = []) : \Aws\Result` defined in `vendor/aws/aws-sdk-php/src/Sns/SnsClient.php:153` (`Saw more required parameters in the override`) |
| `src/Worker/Amazon/SNS/SnsClient.php` | 39 | `PhanParamSignatureMismatch` | Declaration of function `deleteEndpoint(array $data) : mixed` should be compatible with function `deleteEndpoint(array $args = []) : \Aws\Result` defined in `vendor/aws/aws-sdk-php/src/Sns/SnsClient.php:69` (`Saw more required parameters in the override`) |
| `src/Worker/Amazon/SNS/SnsClient.php` | 59 | `PhanParamSignatureMismatch` | Declaration of function `createPlatformEndpoint(array $data) : mixed` should be compatible with function `createPlatformEndpoint(array $args = []) : \Aws\Result` defined in `vendor/aws/aws-sdk-php/src/Sns/SnsClient.php:25` (`Saw more required parameters in the override`) |

### Context

- PHPUnit: **199 tests, 459 assertions, 3 deprecations (OK)**
- phpcs: **0 errors**
- PHPStan: **0 errors**
- Only Phan reports issues

### Solution

Investigate each case of Phan errors file by file.

First priority: Ensure compatibility with external SDK like AWS, when extending parent class or calling external SDK
Second priority: Attempt to apply phan doc-block as fix, or modernize to PHP 8.3 if that will fix
Trird priority: Instead of complex fix, fallback to simple phan ignore rules or blacklist files in phan config to exclude from analysis

