<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use BackQ\Adapter\Amazon\DynamoDb\QueueTableRow;
use InvalidArgumentException;
use function assert;
use function count;
use function crc32;
use function gethostname;
use function getmypid;
use function is_array;
use function json_decode;
use function max;
use function strtotime;
use function time;

/**
 * Adapter uses DynamoDB for writing tasks
 * Adapter uses SQS for pulling tasks
 *
 * DynamoDB -> TTL Expire -> DynamoDB Streams -> AWS Lambda -> SQS
 *
 * @package BackQ\Adapter
 */
class DynamoSQS extends AbstractAdapter
{
    /**
     * Some identifier whatever it is
     */
    public const PARAM_MESSAGE_ID = 'msgid';

    protected const API_VERSION_DYNAMODB  = '2012-08-10';
    protected const API_VERSION_SQS       = '2012-11-05';

    /**
     * Average expected delay from DynamoDB streams to expire items whose TTL was reached
     * (12 minutes)
     */
    protected const DYNAMODB_ESTIMATED_DELAY = 720;

    /**
     * DynamoDB won't process items with a TTL older than 5 years
     */
    protected const DYNAMODB_MAXIMUM_PROCESSABLE_TIME = '5 years';

    protected const TIMEOUT_VISIBILITY_MIN = 10;

    protected ?DynamoDbClient $dynamoDBClient = null;

    protected ?SqsClient $sqsClient = null;

    protected ?string $dynamoDbTableName = null;

    protected ?string $sqsQueueURL = null;

    /**
     * AWS DynamoDB API Key
     */
    protected string $apiKey    = '';

    /**
     * AWS DynamoDB API Secret
     */
    protected string $apiSecret = '';

    /**
     * AWS DynamoDB API Region
     */
    protected string $apiRegion = '';

    /**
     * AWS Account ID
     */
    protected string $apiAccountId = '';

    /**
     * Timeout for receiveMessage from SQS command (Long polling)
     *
     */
    private int $workTimeout = 5;

    private $maxNumberOfMessages = 1;

    public function __construct(string $apiAccountId, string $apiKey, string $apiSecret, string $apiRegion)
    {
        $this->apiKey       = $apiKey;
        $this->apiSecret    = $apiSecret;
        $this->apiRegion    = $apiRegion;
        $this->apiAccountId = $apiAccountId;
    }

    /**
     */
    public function connect(): bool
    {
        $arguments = ['version'     => self::API_VERSION_DYNAMODB,
            'region'      => $this->apiRegion,
            'credentials' => ['key'    => $this->apiKey,
                'secret' => $this->apiSecret]];

        $this->dynamoDBClient = new DynamoDbClient($arguments);

        $arguments['version'] = self::API_VERSION_SQS;
        $this->sqsClient      = new SqsClient($arguments);

        return true;
    }

    /**
     */
    public function disconnect(): bool
    {
        $this->dynamoDBClient    = null;
        $this->dynamoDbTableName = null;
        $this->sqsClient         = null;
        $this->sqsQueueURL       = null;

        return true;
    }

    /**
     * @param string $queue
     */
    public function bindRead($queue): bool
    {
        $this->sqsQueueURL = $this->generateSqsEndpointUrl($queue);

        return true;
    }

    /**
     * @param string $sqsURL
     */
    public function bindWrite($queue): bool
    {
        $this->dynamoDbTableName = $queue;

        return true;
    }

    public function pickTask()
    {
        $this->logDebug(__FUNCTION__);

        $sqs = $this->sqsClient;
        assert($sqs instanceof SqsClient);
        if (!$sqs) {
            return false;
        }

        /**
         * @see https://docs.aws.amazon.com/aws-sdk-php/v2/api/class-Aws.Sqs.SqsClient.html#_receiveMessage
         */
        $result = null;
        try {
            $result = $sqs->receiveMessage(['AttributeNames'        => ['All'],
                'MaxNumberOfMessages'   => $this->maxNumberOfMessages,
                'MessageAttributeNames' => ['All'],
                'QueueUrl'          => $this->sqsQueueURL,
                'WaitTimeSeconds'   => (int) $this->workTimeout,
                'VisibilityTimeout' => $this->calculateVisibilityTimeout()]);
        } catch (AwsException $e) {
            $this->logError($e->getMessage());
        }

        if ($result && $result->hasKey('Messages') && count($result->get('Messages')) > 0) {
            $messagePayload = ($result->get('Messages')[0]);

            $messageBody = @json_decode($messagePayload['Body'], true);
            $itemPayload = null;
            if (is_array($messageBody)) {
                $item = QueueTableRow::fromArray($messageBody);

                if ($item) {
                    $itemPayload = $item->getPayload();
                } else {
                    $this->logError(__FUNCTION__ . ' Invalid received message body');
                }
            } else {
                $this->logError(__FUNCTION__ . ' Unexpected data format on message body');
            }

            $messageId = $messagePayload['ReceiptHandle'];

            return [$messageId, $itemPayload];
        }

        return false;
    }

    public function putTask($body, $params = [])
    {
        $this->logDebug(__FUNCTION__);

        if (!$this->dynamoDBClient) {
            return false;
        }

        $readyTime = time();
        if (isset($params[self::PARAM_READYWAIT])) {
            $readyTime += $this->getEstimatedTTL($params[self::PARAM_READYWAIT]);
        }

        /**
         * Make sure the TTL can be processed by Dynamo
         */
        $minTTL = strtotime('-' . self::DYNAMODB_MAXIMUM_PROCESSABLE_TIME);
        if ($readyTime <= $minTTL) {
            throw new InvalidArgumentException('Cannot process item with TTL: ' . $readyTime);
        }

        $msgid = crc32(getmypid() . gethostname());
        if (isset($params[self::PARAM_MESSAGE_ID])) {
            $msgid = $params[self::PARAM_MESSAGE_ID];
        }

        $item = new QueueTableRow($body, $readyTime, $msgid);
        try {
            $response = $this->dynamoDBClient->putItem(['Item'      => $item->toArray(),
                'TableName' => $this->dynamoDbTableName]);
            if ($response &&
                isset($response['@metadata']['statusCode']) &&
                200 === $response['@metadata']['statusCode']) {
                $this->logDebug(__FUNCTION__ . ' success');

                return true;
            }
        } catch (DynamoDbException $e) {
            $this->logError(__FUNCTION__ . ' service failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param $workId
     */
    public function afterWorkSuccess($workId): bool
    {
        if ($this->sqsClient) {
            $sqs = $this->sqsClient;
            assert($sqs instanceof SqsClient);
            try {
                $sqs->deleteMessage(['QueueUrl' => $this->sqsQueueURL, 'ReceiptHandle' => $workId]);

                return true;
            } catch (AwsException $e) {
                $this->logError($e->getMessage());
            }
        }

        return true;
    }

    /**
     * @phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function afterWorkFailed($workId): bool
    {
        /**
         * Could call SQS ChangeMessageVisibility but why bother, the message will come back after
         * visibility timeout expires (reserved time to process job)
         */
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * @phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function hasWorkers($queue): bool
    {
        /**
         * @todo implement using SQS or DynamoDB as separate table/lock
         */
        return true;
    }

    /**
     * The duration (in seconds) for which the call waits for a message to arrive in the queue before returning.
     * If a message is available, the call returns sooner than $seconds seconds.
     * If no messages are available and the wait time expires,
     * the call returns successfully with an empty list of messages.
     *
     * @param int|null $seconds
     * @return null
     */
    public function setWorkTimeout(?int $seconds = null)
    {
        $this->workTimeout = $seconds;

        return null;
    }

    /**
     * Expected SQS Queue URL
     *
     * https://sqs.REGION.amazonaws.com/XXXXXXXX/QUEUENAME
     *
     * @param string $queue
     */
    private function generateSqsEndpointUrl(string $queue): string
    {
        return 'https://sqs.' . $this->apiRegion . '.amazonaws.com/' . $this->apiAccountId . '/' . $queue;
    }

    private function calculateVisibilityTimeout(): int
    {
        /**
         * How much time we estimate it takes to process the picked results
         */
        return max($this->workTimeout * 4, self::TIMEOUT_VISIBILITY_MIN);
    }

    /**
     * Calculate a TTL value based on the average delay from 'expired' DynamoDB Stream items
     *
     * @param int $expectedTTL
     *
     */
    private function getEstimatedTTL(int $expectedTTL): int
    {
        $estimatedTTL = $expectedTTL;
        if ($expectedTTL >= self::DYNAMODB_ESTIMATED_DELAY) {
            $estimatedTTL -= self::DYNAMODB_ESTIMATED_DELAY;
        }

        return $estimatedTTL;
    }
}
