<?php

namespace BackQ\Tests\Support;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Sqs\SqsClient;
use BackQ\Adapter\DynamoSQS;

/**
 * DynamoSQS double that installs MockHandler-backed clients instead of
 * building real AWS clients inside connect().
 */
class TestDynamoSQS extends DynamoSQS
{
    private DynamoDbClient $dynamo;

    private SqsClient $sqs;

    public function installClients(DynamoDbClient $dynamo, SqsClient $sqs): void
    {
        $this->dynamo = $dynamo;
        $this->sqs    = $sqs;
    }

    public function connect(): bool
    {
        $this->dynamoDBClient = $this->dynamo;
        $this->sqsClient      = $this->sqs;

        return true;
    }
}
