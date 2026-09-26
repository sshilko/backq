<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use Aws\Command;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove as RemoveMessage;
use BackQ\Tests\Support\RecordingLogger;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Remove;
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
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class RemoveWorkerTest extends TestCase
{

    private TestAdapter $adapter;

    private $client;

    public function testQueueNameIsDerivedFromClassName(): void
    {
        $worker = new Remove($this->adapter);
        $this->assertSame('aws_sns_endpoints_remove_', $worker->getQueueName());
        $this->assertSame('', $worker->getPlatform());
    }

    public function testDeletesEndpointOnSns(): void
    {
        $this->adapter->pickTaskResult = [21, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertCount(1, $this->client->deleted);
        $this->assertSame(
            ['EndpointArn' => 'arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz'],
            $this->client->deleted[0]
        );
        $this->assertContains(['afterWorkSuccess', 21], $this->adapter->calls);
    }

    public function testRejectsUnsupportedPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [22, 'garbage'];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->deleted);
        $this->assertContains(['afterWorkSuccess', 22], $this->adapter->calls);
    }

    public function testMarksProcessedOnNotFoundError(): void
    {
        $this->client = new class {
            public function deleteEndpoint(array $payload): array
            {
                throw new SnsException('NotFound', new Command('DeleteEndpoint'), ['code' => 'NotFound']);
            }
        };
        $this->adapter->pickTaskResult = [23, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkSuccess', 23], $this->adapter->calls);
    }

    public function testRetriesOnInternalError(): void
    {
        $this->client = new class {
            public function deleteEndpoint(array $payload): array
            {
                throw new SnsException('InternalError', new Command('DeleteEndpoint'), ['code' => 'InternalError']);
            }
        };
        $this->adapter->pickTaskResult = [24, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 24], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 24], $this->adapter->calls);
    }

    public function testRetriesOnNetworkError(): void
    {
        $this->client = new class {
            public function deleteEndpoint(array $payload): array
            {
                throw new SnsException(
                    'Service Unavailable',
                    new Command('DeleteEndpoint'),
                    [],
                    new NetworkException('network error', new Request('POST', 'https://sns.example.com'))
                );
            }
        };
        $this->adapter->pickTaskResult = [24, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertContains(['afterWorkFailed', 24], $this->adapter->calls);
        $this->assertNotContains(['afterWorkSuccess', 24], $this->adapter->calls);
    }

    public function testSkipsEmptyPayload(): void
    {
        $this->adapter->pickTaskResult = false;

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->deleted);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    public function testRejectsNonStringPayloadAsSuccess(): void
    {
        $this->adapter->pickTaskResult = [33, 123];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->deleted);
        $this->assertContains(['afterWorkSuccess', 33], $this->adapter->calls);
    }

    public function testSkipsJobWithNullTaskId(): void
    {
        $this->adapter->pickTaskResult = [null, serialize($this->makeMessage())];

        $this->makeWorker()->run();

        $this->assertCount(0, $this->client->deleted);
        $this->assertContains(['afterWorkSuccess', null], $this->adapter->calls);
    }

    public function testRetriesExhaustedThenMarksProcessed(): void
    {
        $this->client = new class {
            public function deleteEndpoint(array $payload): array
            {
                throw new SnsException('InternalError', new Command('DeleteEndpoint'), ['code' => 'InternalError']);
            }
        };
        $this->adapter->pickTaskResult = [25, serialize($this->makeMessage())];
        $logger                        = new RecordingLogger();

        $worker = new Remove($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger($logger);
        $worker->setRestartThreshold(4);

        $worker->run();

        $wholeLog = implode("\n", array_column($logger->records, 1));
        $this->assertStringContainsString('Retried re-processing the same job too many times', $wholeLog);
        $this->assertCount(3, $this->filterCalls(['afterWorkFailed', 25]));
        $this->assertCount(1, $this->filterCalls(['afterWorkSuccess', 25]));
    }

    public function testAbandonsJobWhenOnSuccessFails(): void
    {
        $this->adapter->pickTaskResult = [26, serialize($this->makeMessage())];

        $worker = new class ($this->adapter) extends Remove {
            protected function onSuccess(\BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove $message): bool
            {
                return false;
            }
        };
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        $worker->run();

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

        $this->assertStringContainsString('Remove endpoints worker exception', $loggedErrors);
        $this->assertContains('disconnect', $this->adapter->calls);
    }

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
        $this->client  = new class {

            public array $deleted = [];

            public function deleteEndpoint(array $payload): array
            {
                $this->deleted[] = $payload;

                return ['ResponseMetadata' => ['RequestId' => 'r-1']];
            }
        };
    }

    private function makeWorker(): Remove
    {
        $worker = new Remove($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setRestartThreshold(1);

        return $worker;
    }

    private function makeMessage(): RemoveMessage
    {
        $message = new RemoveMessage();
        $message->setEndpointArn('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz');

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
