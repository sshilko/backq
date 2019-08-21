<?php
namespace BackQ\Adapter\Amazon\DynamoDb;

use Aws\Result;

class DynamoDbClient extends \Aws\DynamoDb\DynamoDbClient
{
    /**
     * Creates a new item, or replaces an old item with a new item
     *
     * @see https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_PutItem.html
     * @param array $args
     *
     * @return \Aws\Result|void
     */
    public function putItem(array $args = []): ?Result
    {
        return parent::putItem($args);
    }
}