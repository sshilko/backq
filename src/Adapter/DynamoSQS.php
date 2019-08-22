<?php
namespace BackQ\Adapter;

use BackQ\Adapter\Amazon\DynamoDb\DynamoDbClient;
use BackQ\Adapter\Amazon\DynamoDb\Client\Exception\DynamoDbException;
use BackQ\Adapter\Amazon\DynamoDb\QueueTableRow;

/**
 * Adapter uses DynamoDB for writing tasks
 * Adapter uses SQS for pulling tasks
 *
 * DynamoDB -> TTL Expire -> DynamoDB Streams -> AWS Lambda -> SQS
 *
 * Class DynamoSQS
 * @package BackQ\Adapter
 */
abstract class DynamoSQS extends AbstractAdapter
{
    protected const API_VERSION = '2012-08-10';

    /**
     * Controls how many times and how often the job can be retried on failures
     */
    public const PARAM_RETRYTYPE          = 'retrytype';

    public const RETRYTYPE_FAST_UPTO1HR   = QueueTableRow::RETRYTYPE_FAST_UPTO1HR;
    public const RETRYTYPE_SLOW_UPTO12HRS = QueueTableRow::RETRYTYPE_SLOW_UPTO12HRS;
    public const RETRYTYPE_DEFAULT        = QueueTableRow::RETRYTYPE_FAST_UPTO1HR;

    /**
     * @var ?DynamoDbClient
     */
    protected $dynamoDBClient;

    /**
     * @var
     */
    protected $sqsClient;

    /**
     * @var ?string
     */
    protected $tableName;

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
     * Timeout for receiveMessage from SQS command (Long pooling)
     *
     * @var null|int
     */
    private $workTimeout = null;


    public function __construct(string $apiKey, string $apiSecret, string $apiRegion)
    {
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->apiRegion = $apiRegion;
    }

    /**
     * @return bool
     */
    public function connect()
    {
        $arguments = ['version' => static::API_VERSION,
                       'region' => $this->apiRegion,
                  'credentials' => ['key'    => $this->apiKey,
                                    'secret' => $this->apiSecret]];

        $this->dynamoDBClient = new DynamoDbClient($arguments);
        return true;
    }

    /**
     * @return bool
     */
    public function disconnect()
    {
        $this->dynamoDBClient = null;
        $this->tableName      = null;
        return true;
    }

    /**
     * @param string $queue
     * @return bool
     */
    public function bindRead($queue)
    {
        $this->tableName = $queue;
        return true;
    }

    /**
     * @param string $queue
     * @return bool
     */
    public function bindWrite($queue)
    {
        $this->tableName = $queue;
        return true;
    }

    public function pickTask()
    {
        if ($this->logger) {
            $this->logger->debug(__FUNCTION__);
        }

        /**
         * Todo implement picking from SQS
         */
        sleep(1);
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

        $retry = self::RETRYTYPE_DEFAULT;
        if (isset($params[self::PARAM_RETRYTYPE])) {
            $retry = $params[self::PARAM_RETRYTYPE];
        }

        $item = new QueueTableRow($retry, $body, $readyTime);
        try {
            $response = $this->dynamoDBClient->putItem(['Item' => $item->toArray(),
                'TableName' => $this->tableName]);
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
                /** @var $e DynamoDbException */
                $this->logger->error(__FUNCTION__ . ' service failed: ' . $e->getAwsErrorCode() . $e->getMessage());
            } else {
                $this->logger->error(__FUNCTION__ . ' failed: ' . $e->getMessage());
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
        /**
         * SQS remove from queue
         */
        return true;
    }

    /**
     * @param $workId
     *
     * @return bool
     */
    public function afterWorkFailed($workId)
    {
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
