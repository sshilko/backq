<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish as PublishMessage;
use BackQ\Tests\Support\TestAdapter;
use BackQ\Worker\Amazon\SNS\Application\PlatformEndpoint\Publish;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
        $worker->setTriggerErrorOnError(false);
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

        $worker = new Publish($this->adapter);
        $worker->setClient($this->client);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        $worker->run();

        $this->assertCount(0, $this->client->published);
        $this->assertContains(['afterWorkSuccess', 22], $this->adapter->calls);
    }
}