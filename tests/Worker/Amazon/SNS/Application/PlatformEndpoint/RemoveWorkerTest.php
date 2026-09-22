<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use Aws\Command;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove as RemoveMessage;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Remove;
use BackQ\Worker\Amazon\SNS\Client\Exception\NetworkException;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RemoveWorkerTest extends TestCase
{
    private TestAdapter $adapter;

    private $client;

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
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        return $worker;
    }

    private function makeMessage(): RemoveMessage
    {
        $message = new RemoveMessage();
        $message->setEndpointArn('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz');

        return $message;
    }

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
}
