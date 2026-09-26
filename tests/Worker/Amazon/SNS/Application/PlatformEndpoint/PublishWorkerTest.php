<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use Aws\Command;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish as PublishMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Publish;
use BackQ\Worker\Amazon\SNS\Client\Exception\NetworkException;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @phpcs:disable
 */
class PublishWorkerTest extends TestCase
{
    private TestAdapter $adapter;
    private $client;

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
        $this->client  = new class {
            public array $published = [];

            public function publish(array $payload): array
            {
                $this->published[] = $payload;

                return ['MessageId' => 'm-1'];
            }
        };
    }

    private function makeWorker(): Publish
    {
        $worker = new Publish($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        return $worker;
    }

    private function makeMessage(): PublishMessage
    {
        $message = new PublishMessage();
        $message->setMessage(['default' => 'hi']);
        $message->setTargetArn('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz');

        return $message;
    }

    public function testQueueNameIsDerivedFromClassName(): void
    {
        $worker = new Publish($this->adapter);

        $this->assertSame('aws_sns_endpoints_publish_', $worker->getQueueName());
        $this->assertSame('', $worker->getPlatform());
    }

    public function testPublishesMessageToSnsClient(): void
    {
        $message = new PublishMessage();
        $message->setMessage(['default' => 'hi']);
        $message->setTargetArn('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz');
        $message->setAttributes(['AWS.SNS.MOBILE.APNS.PAYLOAD' => '{"aps":{}}']);
        $message->setMessageStructure('json');

        $this->adapter->pickTaskResult = [21, serialize($message)];

        $worker = new Publish($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertCount(1, $this->client->published);
        $published = $this->client->published[0];
        $this->assertSame('{"default":"hi"}', $published['Message']);
        $this->assertSame('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz', $published['TargetArn']);
        $this->assertSame('json', $published['MessageStructure']);
        $this->assertArrayHasKey('MessageAttributes', $published);
        $this->assertContains(['afterWorkSuccess', 21], $this->adapter->calls);
    }

    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [22, 'garbage'];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->published);
        $this->assertContains(['afterWorkSuccess', 22], $this->adapter->calls);
    }

    public function testRetriesOnInternalError(): void
    {
        $this->client = new class {
            public function publish(array $payload): array
            {
                throw new SnsException('InternalError', new Command('Publish'), ['code' => 'InternalError']);
            }
        };
        $this->adapter->pickTaskResult = [23, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 23], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 23], $this->adapter->calls);
    }

    public function testRetriesOnNetworkError(): void
    {
        $this->client = new class {
            public function publish(array $payload): array
            {
                throw new SnsException(
                    'Service Unavailable',
                    new Command('Publish'),
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

    public function testEndpointDisabledMarksProcessedWithSingleAck(): void
    {
        $this->client = new class {
            public function publish(array $payload): array
            {
                throw new SnsException('EndpointDisabled', new Command('Publish'), ['code' => 'EndpointDisabled']);
            }
        };

        $this->adapter->pickTaskResult = [24, serialize($this->makeMessage())];

        $worker = new class ($this->adapter) extends Publish {
            public array $failures = [];

            protected function onFailure(
                \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish $message,
                string $getAwsErrorCode,
            ): null {
                $this->failures[] = [$message, $getAwsErrorCode];

                return null;
            }
        };
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertCount(1, $worker->failures);
        $this->assertSame('EndpointDisabled', $worker->failures[0][1]);
        $this->assertContains(['afterWorkSuccess', 24], $this->adapter->calls);
        $this->assertNotContains(['afterWorkFailed', 24], $this->adapter->calls);
        $this->assertCount(1, $this->filterCalls(['afterWorkSuccess', 24]));
    }

    public function testRetriesExhaustedThenMarksProcessed(): void
    {
        $this->client = new class {
            public function publish(array $payload): array
            {
                throw new SnsException('InternalError', new Command('Publish'), ['code' => 'InternalError']);
            }
        };
        $this->adapter->pickTaskResult = [25, serialize($this->makeMessage())];

        $worker = $this->makeWorker();
        $worker->setRestartThreshold(4);

        $worker->run();

        $this->assertCount(3, $this->filterCalls(['afterWorkFailed', 25]));
        $this->assertCount(1, $this->filterCalls(['afterWorkSuccess', 25]));
    }

    public function testSkipsEmptyPayload(): void
    {
        $this->adapter->pickTaskResult = false;

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->published);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [33, 123];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->published);
        $this->assertContains(['afterWorkSuccess', 33], $this->adapter->calls);
    }

    public function testSkipsJobWithNullTaskId(): void
    {
        $this->adapter->pickTaskResult = [null, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->published);
        $this->assertContains(['afterWorkSuccess', null], $this->adapter->calls);
    }

    public function testLogsHardErrorOnInternalException(): void
    {
        $this->client = new class {
            public function publish(array $payload): array
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
            $this->adapter->pickTaskResult = [26, serialize($this->makeMessage())];
            $this->makeWorker()->run();
        } finally {
            restore_error_handler();
        }

        $this->assertContains('BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Publish hard failure', $warnings);
        $this->assertContains(['afterWorkSuccess', 26], $this->adapter->calls);
    }

    public function testLogsOuterExceptionOnAckFailure(): void
    {
        $this->adapter->pickTaskResult           = [27, serialize($this->makeMessage())];
        $this->adapter->afterWorkSuccessResult   = false;

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

        $this->assertStringContainsString('SNS worker exception', $loggedErrors);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testLogsUnableToConnect(): void
    {
        $this->adapter->connectResult = false;
        $logger                       = new RecordingLogger();

        $worker = $this->makeWorker();
        $worker->setLogger($logger);
        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Unable to connect', $wholeLog);
        $this->assertNotContains('disconnect', $this->adapter->calls);
    }

    /**
     * @return array<int, array{0:string,1:mixed}>
     */
    private function filterCalls(array $needle): array
    {
        return array_values(array_filter($this->adapter->calls, static fn ($call) => $call === $needle));
    }
}
