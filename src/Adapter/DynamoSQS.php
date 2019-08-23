<?php
namespace BackQ\Adapter;

use Aws\Sqs\SqsClient;
use BackQ\Adapter\Amazon\DynamoDb\DynamoDbClient;
use BackQ\Adapter\Amazon\DynamoDb\Client\Exception\DynamoDbException;
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
        $arguments = ['version' => static::API_VERSION_DYNAMODB,
                       'region' => $this->apiRegion,
                  'credentials' => ['key'    => $this->apiKey,
                                    'secret' => $this->apiSecret]];

        $this->dynamoDBClient = new DynamoDbClient($arguments);

        $arguments['version'] = static::API_VERSION_SQS;
        $this->sqsClient      = new SqsClient($arguments);
        return true;
    }

    /**
     * How max items to pick with each pick cycle
     *
     * @param int $pickN
     */
//    public function setPickBatchSize(int $pickN)
//    {
//        if ($pickN != 1) {
//            throw new \InvalidArgumentException('Please ensure support in worker first');
//        }
//        $this->maxNumberOfMessages = $pickN;
//    }

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
        return 'https://sqs.' . $this->apiRegion . ' . amazonaws.com/' . $this->apiAccountId . '/' . $queue;
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

    public function pickTask()
    {
        if ($this->logger) {
            $this->logger->debug(__FUNCTION__);
        }

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
            if ($this->logger) {
                $this->logger->error($e->getMessage());
            }
        }

        if ($result && count($result->get('Messages')) > 0) {
            $messagePayload = ($result->get('Messages')[0]);
            $messageId      = $messagePayload['ReceiptHandle'];
            return [$messageId, $messagePayload];
        }

        return false;
    }

    public function putTask($body, $params = [])
    {
        if ($this->logger) {
            $this->logger->debug(__FUNCTION__);
        }

        if (!$this->dynamoDBClient) {
            return false;
        }

        $readyTime = time();
        if (isset($params[self::PARAM_READYWAIT])) {
            $readyTime += $params[self::PARAM_READYWAIT];
        }

        $msgid = crc32(getmypid() . gethostname());
        if (isset($params[self::PARAM_MESSAGE_ID])) {
            $msgid = $params[self::PARAM_MESSAGE_ID];
        }

        $item = new QueueTableRow($body, $readyTime, $msgid);
        try {
            $response = $this->dynamoDBClient->putItem(['Item' => $item->toArray(),
                                                        'TableName' => $this->dynamoDbTableName]);
            if ($response &&
                isset($response['@metadata']['statusCode']) &&
                $response['@metadata']['statusCode'] == 200) {

                if ($this->logger) {
                    $this->logger->debug(__FUNCTION__ . ' success');
                }

                return true;
            }
        } catch (\Exception $e) {
            if (is_subclass_of(DynamoDbException::class, get_class($e))) {
                if ($this->logger) {
                    /** @var $e DynamoDbException */
                    $this->logger->error(__FUNCTION__ . ' service failed: ' . $e->getAwsErrorCode() . $e->getMessage());
                }
            } else {
                if ($this->logger) {
                    $this->logger->error(__FUNCTION__ . ' failed: ' . $e->getMessage());
                }
            }
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
                if ($this->logger) {
                    $this->logger->error($e->getMessage());
                }
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
    public function setWorkTimeout(int $seconds = null) {
        $this->workTimeout = $seconds;
        return null;
    }
}
