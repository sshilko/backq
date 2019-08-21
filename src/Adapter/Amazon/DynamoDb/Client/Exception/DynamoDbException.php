<?php
namespace BackQ\Adapter\Amazon\DynamoDb\Client\Exception;

class DynamoDbException extends \Aws\DynamoDb\Exception\DynamoDbException
{
    /**
     * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Exception.AwsException.html#_getAwsErrorType
     *
     * @return string|null
     */
    public function getAwsErrorType() {
        return parent::getAwsErrorType();
    }
}
