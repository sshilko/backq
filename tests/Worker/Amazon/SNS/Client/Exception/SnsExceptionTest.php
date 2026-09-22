<?php

namespace BackQ\Tests\Worker\Amazon\SNS\Client\Exception;

use Aws\Command;
use BackQ\Worker\Amazon\SNS\Client\Exception\SnsException;
use PHPUnit\Framework\TestCase;

class SnsExceptionTest extends TestCase
{
    public function testErrorCodeConstants(): void
    {
        $this->assertSame('InternalError', SnsException::INTERNAL);
        $this->assertSame('InvalidParameter', SnsException::INVALID_PARAM);
        $this->assertSame('EndpointDisabled', SnsException::ENDPOINT_DISABLED);
        $this->assertSame('AuthorizationError', SnsException::AUTHERROR);
        $this->assertSame('NotFound', SnsException::NOTFOUND);
    }

    public function testExposesAwsErrorCodeAndType(): void
    {
        $e = new SnsException('msg', new Command('Publish'), ['code' => 'EndpointDisabled', 'type' => 'Sender']);

        $this->assertSame('EndpointDisabled', $e->getAwsErrorCode());
        $this->assertSame('Sender', $e->getAwsErrorType());
    }

    public function testMissingContextYieldsNullCodes(): void
    {
        $e = new SnsException('msg', new Command('Publish'));

        $this->assertNull($e->getAwsErrorCode());
        $this->assertNull($e->getAwsErrorType());
    }
}
