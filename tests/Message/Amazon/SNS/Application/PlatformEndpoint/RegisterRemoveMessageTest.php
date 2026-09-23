<?php

namespace BackQ\Tests\Message\Amazon\SNS\Application\PlatformEndpoint;

use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Register;
use BackQ\Message\Amazon\SNS\Application\PlatformEndpoint\Remove;
use PHPUnit\Framework\TestCase;

class RegisterRemoveMessageTest extends TestCase
{
    public function testRegister(): void
    {
        $message = new Register();
        $message->addToken('device-token');
        $message->setApplicationArn('arn:aws:sns:us-east-1:123:app/APNS/app');
        $message->setAttributes(['Enabled' => 'true']);

        $this->assertSame('device-token', $message->getToken());
        $this->assertSame('arn:aws:sns:us-east-1:123:app/APNS/app', $message->getApplicationArn());
        $this->assertSame(['Enabled' => 'true'], $message->getAttributes());
    }

    public function testRemove(): void
    {
        $message = new Remove();
        $message->setEndpointArn('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz');

        $this->assertSame('arn:aws:sns:us-east-1:123:endpoint/APNS/app/xyz', $message->getEndpointArn());
    }
}
