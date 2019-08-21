<?php
namespace BackQ\Adapter\WriteOnly;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\Amazon\DynamoDb\DynamoDbClient;
use BackQ\Adapter\Amazon\DynamoDb\Client\Exception\DynamoDbException;

class Scheduled extends AbstractAdapter
{
    /**
     * Controls how many times and how often the job can be retried on failures
     */
    public const PARAM_RETRYTYPE = 'retrytype';

    public const FAST_RETRY_UP1HR   = 'retry_fast';
    public const SLOW_RETRY_UP12HRS = 'retry_slow';

    /**
     * DynamoDb datatypes
     */
    public const DYNAMODB_TYPE_STRING = 'S';
    public const DYNAMODB_TYPE_NUMBER = 'N';

    /**
     * 'Column' names on DynamoDb table
     */
    protected const COLUMN_ID       = 'id';
    protected const COLUMN_PAYLOAD  = 'payload';
    protected const COLUMN_METADATA = 'metadata';
    protected const COLUMN_TTL      = 'time_ready';

    protected const METADATA_CHECKSUM = 'payload_checksum';

    protected const REQUIRED = [self::COLUMN_ID,
        self::COLUMN_PAYLOAD,
        self::COLUMN_METADATA];

    /**
     * @var DynamoDbClient
     */
    protected $dynamoDBClient;

    /**
     * @var string
     */
    protected $tableName;

    public function __construct(string $tableName, array $parameters)
    {
        $this->tableName      = $tableName;
        $this->dynamoDBClient = new DynamoDbClient($parameters);
    }

    /**
     * @return bool
     */
    public function connect()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function disconnect()
    {
        $this->dynamoDBClient = null;
        return true;
    }

    /**
     * @param $queue
     */
    public function bindRead($queue)
    {
        throw new \InvalidArgumentException(__FUNCTION__  . ' is not supported for the adapter type');
    }

    public function bindWrite($queue)
    {
        return true;
    }

    public function pickTask()
    {
        throw new \InvalidArgumentException(__FUNCTION__ . ' is not supported for the adapter type');
    }


    public function putTask($body, $params = [])
    {
        if ($this->logger) {
            $this->logger->debug(__FUNCTION__);
        }

        if ($this->dynamoDBClient) {
            $meta = [];
            $item = [];

            $readyTime = time();
            if (isset($params[self::PARAM_READYWAIT])) {
                $readyTime += $params[self::PARAM_READYWAIT];
            }

            if (isset($params[self::PARAM_RETRYTYPE])) {
                $meta[self::PARAM_RETRYTYPE] = $params[self::PARAM_RETRYTYPE];
            }
            $meta[self::METADATA_CHECKSUM] = crc32($body);

            $item[self::COLUMN_ID]       = [self::DYNAMODB_TYPE_STRING => uniqid()];
            $item[self::COLUMN_METADATA] = [self::DYNAMODB_TYPE_STRING => json_encode($meta)];
            $item[self::COLUMN_PAYLOAD]  = [self::DYNAMODB_TYPE_STRING => $body];
            $item[self::COLUMN_TTL]      = [self::DYNAMODB_TYPE_NUMBER => (string) $readyTime];
        }

        try {
            $response = $this->dynamoDBClient->putItem(['Item' => $item, 'TableName' => $this->tableName]);
            if (isset($response['@metadata']['statusCode']) && $response['@metadata']['statusCode'] == 200) {
                if ($this->logger) {
                    $this->logger->debug(__FUNCTION__ . ' success');
                }

                return true;
            }
        } catch (\Exception $e) {

            if (is_subclass_of(DynamoDbException::class, get_class($e))) {
                /** @var $e DynamoDbException */
                trigger_error(__FUNCTION__ . ' service failed: ' . $e->getAwsErrorCode() . $e->getMessage(), E_USER_WARNING);
            } else {
                trigger_error(__FUNCTION__ . ' failed: ' . $e->getMessage(), E_USER_WARNING);
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
        return true;
    }

    /**
     * @param int|null $seconds
     *
     * @return void|null
     */
    public function setWorkTimeout(int $seconds = null)
    {
        // TODO: Implement setWorkTimeout() method.
    }
}
