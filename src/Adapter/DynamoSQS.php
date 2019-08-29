<?php
namespace BackQ\Adapter;

use Aws\Sqs\SqsClient;
use Aws\DynamoDb\DynamoDbClient;
use BackQ\Adapter\Amazon\DynamoDb\QueueTableRow;
use Aws\Exception\AwsException;

/**
 * Adapter uses DynamoDB for writing tasks
 * Adapter uses SQS for pulling tasks
 *
 * DynamoDB -> TTL Expire -> DynamoDB Streams -> AWS Lambda -> SQS
 *
 * Class DynamoSQSSlow
 * @package BackQ\Adapter
 */
class DynamoSQS extends AbstractAdapter
{
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

    /**
     * Some identifier whatever it is
     */
    public const PARAM_MESSAGE_ID = 'msgid';

    /**
     * Controls how many times and how often the job can be retried on failures
     */

    /**
     * @var ?DynamoDbClient
     */
    protected $dynamoDBClient;

    /**
     * @var ?SqsClient
     */
    protected $sqsClient;

    /**
     * @var ?string
     */
    protected $dynamoDbTableName;

    /**
     * @var ?string
     */
    protected $sqsQueueURL;

    /**
     * AWS DynamoDB API Key
     * @var string
     */
    protected $apiKey    = '';

    /**
     * AWS DynamoDB API Secret
     * @var string
     */
    protected $apiSecret = '';

    /**
     * AWS DynamoDB API Region
     * @var string
     */
    protected $apiRegion = '';

    /**
     * AWS Account ID
     * @var string
     */
    protected $apiAccountId = '';

    /**
     * Timeout for receiveMessage from SQS command (Long polling)
     *
     * @var int
     */
    private $workTimeout = 5;

    private $maxNumberOfMessages = 1;

    public function __construct(string $apiAccountId,
                                string $apiKey,
                                string $apiSecret,
                                string $apiRegion)
    {
        $this->apiKey       = $apiKey;
        $this->apiSecret    = $apiSecret;
        $this->apiRegion    = $apiRegion;
        $this->apiAccountId = $apiAccountId;
    }

    /**
     * @return bool
     */
    public function connect()
    {
        $arguments = ['version'     => static::API_VERSION_DYNAMODB,
                      'region'      => $this->apiRegion,
                      'credentials' => ['key'    => $this->apiKey,
                                        'secret' => $this->apiSecret]];

        $this->dynamoDBClient = new DynamoDbClient($arguments);

        $arguments['version'] = static::API_VERSION_SQS;
        $this->sqsClient      = new SqsClient($arguments);
        return true;
    }

    /**
     * @return bool
     */
    public function disconnect()
    {
        $this->dynamoDBClient    = null;
        $this->dynamoDbTableName = null;
        $this->sqsClient         = null;
        $this->sqsQueueURL       = null;
        return true;
    }

    /**
     * @param string $queue
     * @return bool
     */
    public function bindRead($queue)
    {
        $this->sqsQueueURL = $this->generateSqsEndpointUrl($queue);
        return true;
    }

    /**
     * Expected SQS Queue URL
     *
     * https://sqs.REGION.amazonaws.com/XXXXXXXX/QUEUENAME
     *
     * @param string $queue
     * @return string
     */
    private function generateSqsEndpointUrl(string $queue): string
    {
        return 'https://sqs.' . $this->apiRegion . '.amazonaws.com/' . $this->apiAccountId . '/' . $queue;
    }

    /**
     * @param string $sqsURL
     * @return bool
     */
    public function bindWrite($queue)
    {
        $this->dynamoDbTableName = $queue;
        return true;
    }

    private function calculateVisibilityTimeout(): int
    {
        /**
         * How much time we estimate it takes to process the picked results
         */
        return max($this->workTimeout * 4, 10);
    }

    /**
     * Calculate a TTL value based on the average delay from 'expired' DynamoDB Stream items
     *
     * @param int $expectedTTL
     *
     * @return int
     */
    private function getEstimatedTTL(int $expectedTTL): int
    {
        $estimatedTTL = $expectedTTL;
        if ($expectedTTL >= self::DYNAMODB_ESTIMATED_DELAY) {
            $estimatedTTL -= self::DYNAMODB_ESTIMATED_DELAY;
        }

        return $estimatedTTL;
    }

    public function pickTask()
    {
        $this->logDebug(__FUNCTION__);

        /** @var SqsClient $sqs */
        $sqs = $this->sqsClient;
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
        }  catch (AwsException $e) {
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
            throw new \InvalidArgumentException('Cannot process item with TTL: ' . $readyTime);
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
                $response['@metadata']['statusCode'] == 200) {

                $this->logDebug(__FUNCTION__ . ' success');

                return true;
            }
        } catch (\Aws\DynamoDb\Exception\DynamoDbException $e) {
            $this->logError(__FUNCTION__ . ' service failed: ' . $e->getAwsErrorCode() . $e->getMessage());
        } catch (\Exception $e) {
            $this->logError(__FUNCTION__ . ' failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param $workId
     *
     * @return bool
     */
    public function afterWorkSuccess($workId)
    {
        if ($this->sqsClient) {
            /** @var SqsClient $sqs */
            $sqs = $this->sqsClient;
            try {
                $sqs->deleteMessage(['QueueUrl' => $this->sqsQueueURL, 'ReceiptHandle' => $workId]);
                return true;
            }  catch (AwsException $e) {
                $this->logError($e->getMessage());
            }
        }
        return true;
    }

    /**
     * @param $workId
     *
     * @return bool
     */
    public function afterWorkFailed($workId)
    {
        /**
         * Could call SQS ChangeMessageVisibility but why bother, the message will come back after
         * visibility timeout expires (reserved time to process job)
         */
        return true;
    }

    /**
     * @return bool
     */
    public function ping()
    {
        return true;
    }

    /**
     * @param $queue
     *
     * @return bool
     */
    public function hasWorkers($queue)
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
     * @param null|int $seconds
     * @return null
     */
    public function setWorkTimeout(int $seconds = null)
    {
        $this->workTimeout = $seconds;
        return null;
    }
}
