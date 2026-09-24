<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use Aws\Command;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register as RegisterMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Register;
use BackQ\Worker\Amazon\SNS\Client\Exception\NetworkException;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use function array_column;
use function array_filter;
use function array_values;
use function file_exists;
use function file_get_contents;
use function implode;
use function ini_get;
use function ini_set;
use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use const E_USER_WARNING;

class RegisterWorkerTest extends TestCase
{

    private TestAdapter $adapter;

    private $client;

    public function testQueueNameIsDerivedFromClassName(): void
    {
        $worker = new Register($this->adapter);
        $this->assertSame('aws_sns_endpoints_register_', $worker->getQueueName());
        $this->assertSame('', $worker->getPlatform());
    }

    public function testRegistersEndpointOnSns(): void
    {
        $this->adapter->pickTaskResult = [21, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertCount(1, $this->client->created);
        $created = $this->client->created[0];
        $this->assertSame('arn:aws:sns:us-east-1:123:app/APNS/app', $created['PlatformApplicationArn']);
        $this->assertSame('device-token', $created['Token']);
        $this->assertSame(['Enabled' => 'true'], $created['Attributes']);
        $this->assertContains(['afterWorkSuccess', 21], $this->adapter->calls);
    }

    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [22, 'garbage'];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->created);
        $this->assertContains(['afterWorkSuccess', 22], $this->adapter->calls);
    }

    public function testRetriesOnInternalError(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                throw new SnsException(
                    'InternalError',
                    new Command('CreatePlatformEndpoint'),
                    ['code' => 'InternalError']
                );
            }
        };
        $this->adapter->pickTaskResult = [23, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 23], $this->adapter->calls);
    }

    public function testRetriesOnNetworkError(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                throw new SnsException(
                    'Service Unavailable',
                    new Command('CreatePlatformEndpoint'),
                    [],
                    new NetworkException('network error', new Request('POST', 'https://sns.example.com'))
                );
            }
        };
        $this->adapter->pickTaskResult = [23, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 23], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 23], $this->adapter->calls);
    }

    public function testMarksProcessedOnAuthorizationError(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                throw new SnsException(
                    'AuthorizationError',
                    new Command('CreatePlatformEndpoint'),
                    ['code' => 'AuthorizationError']
                );
            }
        };
        $this->adapter->pickTaskResult = [24, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkSuccess', 24], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 24], $this->adapter->calls);
    }

    public function testOnSuccessFailureAbandonsJobWithoutAck(): void
    {
        $this->adapter->pickTaskResult = [25, serialize($this->makeMessage())];

        $worker = new class ($this->adapter) extends Register {

            public $receivedArn = null;

            protected function onSuccess(
                string $endpointArn,
                \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register $message,
            ): bool {
                $this->receivedArn = $endpointArn;

                return false;
            }
        };
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertSame('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz', $worker->receivedArn);
        $this->assertNotContains(['afterWorkSuccess', 25], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 25], $this->adapter->calls);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testSkipsEmptyPayload(): void
    {
        $this->adapter->pickTaskResult = false;

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->created);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [33, 123];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->created);
        $this->assertContains(['afterWorkSuccess', 33], $this->adapter->calls);
    }

    public function testSkipsJobWithNullTaskId(): void
    {
        $this->adapter->pickTaskResult = [null, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->created);
        $this->assertContains(['afterWorkSuccess', null], $this->adapter->calls);
    }

    public function testRetriesExhaustedThenMarksProcessed(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                throw new SnsException(
                    'InternalError',
                    new Command('CreatePlatformEndpoint'),
                    ['code' => 'InternalError']
                );
            }
        };
        $this->adapter->pickTaskResult = [25, serialize($this->makeMessage())];
        $logger                        = new RecordingLogger();

        $worker = new Register($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger($logger);
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(4);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Retried re-processing the same job too many times', $wholeLog);
        $this->assertCount(3, $this->filterCalls(['afterWorkFailed', 25]));
        $this->assertCount(1, $this->filterCalls(['afterWorkSuccess', 25]));
    }

    public function testAbandonsJobWhenSnsReturnsEmptyResult(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                return [];
            }
        };
        $this->adapter->pickTaskResult = [26, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 26], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 26], $this->adapter->calls);
    }

    public function testLogsOuterExceptionOnAckFailure(): void
    {
        $this->adapter->pickTaskResult         = [27, serialize($this->makeMessage())];
        $this->adapter->afterWorkSuccessResult = false;

        $errorLog = tempnam(sys_get_temp_dir(), 'snserr_');
        $previous = ini_get('error_log');
        ini_set('error_log', $errorLog);

        try {
            $this->makeWorker()->run();
        } finally {
            ini_set('error_log', $previous);
        }

        $loggedErrors = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        unlink($errorLog);

        $this->assertStringContainsString('Register SNS worker exception', $loggedErrors);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testLogsHardErrorOnInternalException(): void
    {
        $this->client = new class {
            public function createPlatformEndpoint(array $payload): array
            {
                throw new \RuntimeException('hard failure');
            }
        };

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->adapter->pickTaskResult = [28, serialize($this->makeMessage())];
            $this->makeWorker()->run();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(0, $warnings);
        $this->assertContains(['afterWorkFailed', 28], $this->adapter->calls);
    }

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
        $this->client  = new class {

            public array $created = [];

            public function createPlatformEndpoint(array $payload): array
            {
                $this->created[] = $payload;

                return ['EndpointArn' => 'arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz'];
            }
        };
    }

    private function makeWorker(): Register
    {
        $worker = new Register($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        return $worker;
    }

    private function makeMessage(): RegisterMessage
    {
        $message = new RegisterMessage();
        $message->addToken('device-token');
        $message->setApplicationArn('arn:aws:sns:us-east-1:123:app/APNS/app');
        $message->setAttributes(['Enabled' => 'true']);

        return $message;
    }

    /**
     * @return array<int, array{0:string,1:mixed}>
     */
    private function filterCalls(array $needle): array
    {
        return array_values(array_filter($this->adapter->calls, static function ($call) use ($needle): bool {
            return $call === $needle;
        }));
    }
}
