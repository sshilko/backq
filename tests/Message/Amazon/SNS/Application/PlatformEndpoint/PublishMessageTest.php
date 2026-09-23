<?php

namespace BackQ\Tests\Message\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Publish;
use PHPUnit\Framework\TestCase;

class PublishMessageTest extends TestCase
{
    public function testDefaults(): void
    {
        $message = new Publish();
        $message->setMessage(['default' => 'hello']);
        $message->setTargetArn('arn:aws:sns:us-east-1:123:endpoint/abc');

        $this->assertSame('{"default":"hello"}', $message->getMessage());
        $this->assertSame('{"default":"hello"}', json_encode(['default' => 'hello']));
        $this->assertSame('arn:aws:sns:us-east-1:123:endpoint/abc', $message->getTargetArn());
    }

    public function testAttributesAndStructure(): void
    {
        $message = new Publish();
        $message->setAttributes(['AWS.SNS.MOBILE.APNS.PAYLOAD' => '{"aps":{"alert":"x"}}']);

        $this->assertSame(['AWS.SNS.MOBILE.APNS.PAYLOAD' => '{"aps":{"alert":"x"}}'], $message->getAttributes());

        $message->setMessageStructure('json');

        $this->assertSame('json', $message->getMessageStructure());
    }

    public function testMessageRoundTrip(): void
    {
        $message = new Publish();
        $message->setMessage(['custom' => ['key' => 'value']]);

        $this->assertSame('{"custom":{"key":"value"}}', $message->getMessage());
        $this->assertSame(['custom' => ['key' => 'value']], json_decode($message->getMessage(), true));
    }
}
