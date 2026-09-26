<?php

namespace BackQ\Tests\Publisher\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Publish as PublishPublisher;
use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Register as RegisterPublisher;
use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Remove as RemovePublisher;
use BackQ\Tests\Support\TestAdapter;
use PHPUnit\Framework\TestCase;

class PlatformEndpointPublisherTest extends TestCase
{
    public function testPublishPublisherQueueName(): void
    {
        $publisher = new class (new TestAdapter()) extends PublishPublisher {
        };

        $this->assertSame('aws_sns_endpoints_publish_', $publisher->getQueueName());
    }

    public function testRegisterPublisherQueueName(): void
    {
        $publisher = new class (new TestAdapter()) extends RegisterPublisher {
        };

        $this->assertSame('aws_sns_endpoints_register_', $publisher->getQueueName());
    }

    public function testRemovePublisherQueueName(): void
    {
        $publisher = new class (new TestAdapter()) extends RemovePublisher {
        };

        $this->assertSame('aws_sns_endpoints_remove_', $publisher->getQueueName());
    }
}
