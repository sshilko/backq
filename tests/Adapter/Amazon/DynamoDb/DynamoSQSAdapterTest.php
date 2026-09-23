<?php

namespace BackQ\Tests\Adapter\Amazon\DynamoDb;

use Aws\Command;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use BackQ\Adapter\DynamoSQS;
use BackQ\Tests\Support\TestDynamoSQS;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use function crc32;
use function json_decode;
use function json_encode;
use function time;

class DynamoSQSAdapterTest extends TestCase
{
    private const string ACCOUNT_ID = '123456789012';

    private const string REGION = 'us-east-1';

    private const string QUEUE = 'workqueue';

    private const string TABLE = 'jobstable';

    public function testPutTaskWritesExpectedItem(): void
    {
        $dynamo  = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter($dynamo, new MockHandler([]));

        $before = time();
        $this->assertTrue($adapter->putTask('job-body', [DynamoSQS::PARAM_MESSAGE_ID => 42]));

        $body = json_decode((string) $dynamo->getLastRequest()->getBody(), true);

        $this->assertSame(self::TABLE, $body['TableName']);
        $this->assertStringStartsWith('42.', $body['Item']['id']['S']);
        $this->assertSame('job-body', $body['Item']['payload']['S']);
        $this->assertSame(['payload_checksum' => crc32('job-body')], json_decode($body['Item']['metadata']['S'], true));

        $timeReady = (int) $body['Item']['time_ready']['N'];
        $this->assertGreaterThanOrEqual($before, $timeReady);
        $this->assertLessThanOrEqual(time() + 1, $timeReady);
    }

    public function testPutTaskDefaultMessageIdWhenNoParam(): void
    {
        $dynamo  = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter($dynamo, new MockHandler([]));

        $this->assertTrue($adapter->putTask('job-body'));

        $body = json_decode((string) $dynamo->getLastRequest()->getBody(), true);
        $this->assertMatchesRegularExpression('/^[0-9]+\.[a-f0-9]{13}$/', $body['Item']['id']['S']);
    }

    public function testPutTaskFailsClosedWhenDynamoErrors(): void
    {
        $exception = new DynamoDbException(
            'table missing',
            new Command('PutItem'),
            ['code' => 'ResourceNotFoundException']
        );
        $dynamo    = new MockHandler([$exception]);
        $adapter   = $this->makeAdapter($dynamo, new MockHandler([]));
        $adapter->setTriggerErrorOnError(false);

        $this->assertFalse($adapter->putTask('job-body'));
    }

    public function testPutTaskFailsWhenNotConnected(): void
    {
        $adapter = new TestDynamoSQS(self::ACCOUNT_ID, 'key', 'secret', self::REGION);

        $this->assertFalse($adapter->putTask('job-body'));
    }

    public function testPutTaskReadywaitBelowEstimatedDelayIsUsedDirectly(): void
    {
        $dynamo  = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter($dynamo, new MockHandler([]));

        $before = time();
        $this->assertTrue($adapter->putTask('x', [DynamoSQS::PARAM_READYWAIT => 100]));

        $timeReady = (int) json_decode((string) $dynamo->getLastRequest()->getBody(), true)['Item']['time_ready']['N'];
        $this->assertGreaterThanOrEqual($before + 100, $timeReady);
        $this->assertLessThanOrEqual(time() + 100, $timeReady);
    }

    public function testPutTaskReadywaitAboveEstimatedDelayIsReduced(): void
    {
        $dynamo  = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter($dynamo, new MockHandler([]));

        $before = time();
        $this->assertTrue($adapter->putTask('x', [DynamoSQS::PARAM_READYWAIT => 1000]));

        $timeReady = (int) json_decode((string) $dynamo->getLastRequest()->getBody(), true)['Item']['time_ready']['N'];
        $this->assertGreaterThanOrEqual($before + 280, $timeReady);
        $this->assertLessThanOrEqual(time() + 280, $timeReady);
    }

    public function testPutTaskThrowsForUnprocessableTtl(): void
    {
        $adapter = $this->makeAdapter(new MockHandler([]), new MockHandler([]));

        $this->expectException(InvalidArgumentException::class);

        $adapter->putTask('x', [DynamoSQS::PARAM_READYWAIT => -200000000]);
    }

    public function testPickTaskReturnsPayloadAndAcknowledges(): void
    {
        $sqs     = new MockHandler([new Result(['Messages' => [
            ['Body'          => $this->rowPayload('job-body', crc32('job-body')),
                'ReceiptHandle' => 'rh-1'],
        ]])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);

        $result = $adapter->pickTask();

        $this->assertSame(['rh-1', 'job-body'], $result);

        $command = $sqs->getLastCommand();
        $this->assertSame('ReceiveMessage', $command->getName());
        $this->assertSame($this->sqsEndpointUrl(), $command['QueueUrl']);
        $this->assertSame(5, $command['WaitTimeSeconds']);
        $this->assertSame(20, $command['VisibilityTimeout']);
        $this->assertSame(1, $command['MaxNumberOfMessages']);
    }

    public function testPickTaskHonorsWorkTimeout(): void
    {
        $sqs     = new MockHandler([new Result(['Messages' => [
            ['Body'          => $this->rowPayload('job-body', crc32('job-body')),
                'ReceiptHandle' => 'rh-1'],
        ]])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);
        $adapter->setWorkTimeout(30);

        $this->assertSame(['rh-1', 'job-body'], $adapter->pickTask());

        $command = $sqs->getLastCommand();
        $this->assertSame(30, $command['WaitTimeSeconds']);
        $this->assertSame(120, $command['VisibilityTimeout']);
    }

    public function testPickTaskReturnsNullPayloadOnMalformedBody(): void
    {
        $sqs     = new MockHandler([new Result(['Messages' => [
            ['Body'          => 'not-a-json-body',
                'ReceiptHandle' => 'rh-2'],
        ]])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);
        $adapter->setTriggerErrorOnError(false);

        $this->assertSame(['rh-2', null], $adapter->pickTask());
    }

    public function testPickTaskReturnsNullPayloadOnChecksumMismatch(): void
    {
        $sqs     = new MockHandler([new Result(['Messages' => [
            ['Body'          => $this->rowPayload('job-body', crc32('tampered')),
                'ReceiptHandle' => 'rh-3'],
        ]])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);
        $adapter->setTriggerErrorOnError(false);

        $this->assertSame(['rh-3', null], $adapter->pickTask());
    }

    public function testPickTaskReturnsFalseOnEmptyMessageList(): void
    {
        $sqs     = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);

        $this->assertFalse($adapter->pickTask());
    }

    public function testPickTaskReturnsFalseOnAwsException(): void
    {
        $exception = new AwsException('queue down', new Command('ReceiveMessage'));
        $sqs       = new MockHandler([$exception]);
        $adapter   = $this->makeAdapter(new MockHandler([]), $sqs);
        $adapter->setTriggerErrorOnError(false);

        $this->assertFalse($adapter->pickTask());
    }

    public function testAfterWorkSuccessDeletesByReceiptHandle(): void
    {
        $sqs     = new MockHandler([new Result([])]);
        $adapter = $this->makeAdapter(new MockHandler([]), $sqs);

        $this->assertTrue($adapter->afterWorkSuccess('rh-9'));

        $command = $sqs->getLastCommand();
        $this->assertSame('DeleteMessage', $command->getName());
        $this->assertSame('rh-9', $command['ReceiptHandle']);
        $this->assertSame($this->sqsEndpointUrl(), $command['QueueUrl']);
    }

    public function testAfterWorkSuccessOnMissingClientIsIdempotent(): void
    {
        $adapter = new TestDynamoSQS(self::ACCOUNT_ID, 'key', 'secret', self::REGION);

        $this->assertTrue($adapter->afterWorkSuccess('rh-1'));
    }

    public function testAfterWorkFailedIsTolerantNoOp(): void
    {
        $adapter = $this->makeAdapter(new MockHandler([]), new MockHandler([]));

        $this->assertTrue($adapter->afterWorkFailed('rh-1'));
    }

    private function makeAdapter(MockHandler $dynamo, MockHandler $sqs): TestDynamoSQS
    {
        $adapter = new TestDynamoSQS(self::ACCOUNT_ID, 'key', 'secret', self::REGION);
        $adapter->installClients(
            new DynamoDbClient([                'credentials' => ['key'    => 'key',
                'secret' => 'secret'],
                'handler'     => $dynamo,
                'region'      => self::REGION,
                'version'     => '2012-08-10',
            ]),
            new SqsClient([             'credentials' => ['key'    => 'key',
                'secret' => 'secret'],
                'handler'     => $sqs,
                'region'      => self::REGION,
                'version'     => '2012-11-05',
            ])
        );
        $adapter->connect();
        $adapter->bindRead(self::QUEUE);
        $adapter->bindWrite(self::TABLE);

        return $adapter;
    }

    private function rowPayload(string $payload, int $checksum): string
    {
        return (string) json_encode(['id'         => 'q1.abc',
            'metadata'   => json_encode(['payload_checksum' => $checksum]),
            'payload'    => $payload,
            'time_ready' => 60]);
    }

    private function sqsEndpointUrl(): string
    {
        return 'https://sqs.' . self::REGION . '.amazonaws.com/' . self::ACCOUNT_ID . '/' . self::QUEUE;
    }
}
