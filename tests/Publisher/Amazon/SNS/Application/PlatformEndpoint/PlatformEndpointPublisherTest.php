<?php

namespace BackQ\Tests\Publisher\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Publish as PublishPublisher;
use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Register as RegisterPublisher;
use BackQ\Publisher\Amazon\SNS\Application\PlatformEndpoint\Remove as RemovePublisher;
use BackQ\Tests\Support\TestAdapter;
use PHPUnit\Framework\TestCase;

class PlatformEndpointPublisherTest extends TestCase
{
    public function testPublishPublisherQueueName(): void
    {
        $publisher = new class extends PublishPublisher {
            public function __construct()
            {
                parent::__construct();
            }

            protected function setupAdapter(): AbstractAdapter
            {
                return new TestAdapter();
            }
        };

        $this->assertSame('aws_sns_endpoints_publish_', $publisher->getQueueName());
    }

    public function testRegisterPublisherQueueName(): void
    {
        $publisher = new class extends RegisterPublisher {
            public function __construct()
            {
                parent::__construct();
            }

            protected function setupAdapter(): AbstractAdapter
            {
                return new TestAdapter();
            }
        };

        $this->assertSame('aws_sns_endpoints_register_', $publisher->getQueueName());
    }

    public function testRemovePublisherQueueName(): void
    {
        $publisher = new class extends RemovePublisher {
            public function __construct()
            {
                parent::__construct();
            }

            protected function setupAdapter(): AbstractAdapter
            {
                return new TestAdapter();
            }
        };

        $this->assertSame('aws_sns_endpoints_remove_', $publisher->getQueueName());
    }
}
