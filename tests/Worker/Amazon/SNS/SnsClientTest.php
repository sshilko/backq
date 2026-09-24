<?php

namespace BackQ\Tests\Worker\Amazon\SNS;

use Aws\MockHandler;
use Aws\Result;
use BackQ\Worker\Amazon\SNS\SnsClient;
use PHPUnit\Framework\TestCase;

class SnsClientTest extends TestCase
{

    public function testPublishWrapper(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['MessageId' => 'm-9']));
        $client = $this->makeClient($handler);

        $result = $client->publish(['Message' => 'hello', 'TargetArn' => 'arn:target']);

        $this->assertSame('m-9', $result['MessageId']);
    }

    public function testDeleteEndpointWrapper(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['ResponseMetadata' => ['RequestId' => 'r-1']]));
        $client = $this->makeClient($handler);

        $result = $client->deleteEndpoint(['EndpointArn' => 'arn:endpoint']);

        $this->assertSame('r-1', $result['ResponseMetadata']['RequestId']);
    }

    public function testCreatePlatformEndpointWrapper(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['EndpointArn' => 'arn:endpoint']));
        $client = $this->makeClient($handler);

        $result = $client->createPlatformEndpoint(['Token' => 'tok', 'PlatformApplicationArn' => 'arn:app']);

        $this->assertSame('arn:endpoint', $result['EndpointArn']);
    }

    private function makeClient(MockHandler $handler): SnsClient
    {
        return new SnsClient([
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler'     => $handler,
            'region'      => 'us-east-1',
            'version'     => 'latest',
        ]);
    }
}
