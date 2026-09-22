<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use Aws\Command;
use Aws\Sns\Exception\SnsException;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register as RegisterMessage;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Register;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RegisterWorkerTest extends TestCase
{
    private TestAdapter $adapter;

    private $client;

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
    }

    public function testOnSuccessFailureAbandonsJobWithoutAck(): void
    {
        $this->adapter->pickTaskResult = [25, serialize($this->makeMessage())];

        $worker = new class ($this->adapter) extends Register {
            public $receivedArn = null;

            protected function onSuccess(
                string $endpointArn,
                \BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register $message
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
}
