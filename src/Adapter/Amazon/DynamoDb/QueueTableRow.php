<?php
namespace BackQ\Adapter\Amazon\DynamoDb;

class QueueTableRow
{
    private const DYNAMODB_TYPE_STRING = 'S';
    private const DYNAMODB_TYPE_NUMBER = 'N';

    public  const RETRYTYPE_FAST_UPTO1HR   = 'retry_fast';
    public  const RETRYTYPE_SLOW_UPTO12HRS = 'retry_slow';

    protected $id;
    protected $payload;
    protected $metadata = [];
    protected $time_ready;

    public function __construct(string $retry, string $body, int $readyTime, string $queueId = "")
    {
        $this->id         = uniqid($queueId . '.', false);
        $this->payload    = $body;
        $this->time_ready = (string) $readyTime;

        if ($this->isValidRetry($retry)) {
            $this->metadata['retrytype'] = $retry;
        } else {
            throw new \InvalidArgumentException('Unknown retry type `' . $retry . '`');
        }
        $this->metadata['payload_checksum'] = $this->calculateHMAC();
    }

    private function isValidRetry(string $retry): bool
    {
        return (in_array($retry, [self::RETRYTYPE_FAST_UPTO1HR, self::RETRYTYPE_SLOW_UPTO12HRS]));
    }

    private function calculateHMAC(): int
    {
        return crc32($this->payload);
    }

    public function toArray(): array
    {
        return ['id'         => [self::DYNAMODB_TYPE_STRING => $this->id],
                'metadata'   => [self::DYNAMODB_TYPE_STRING => json_encode($this->metadata)],
                'payload'    => [self::DYNAMODB_TYPE_STRING => $this->payload],
                'time_ready' => [self::DYNAMODB_TYPE_NUMBER => $this->time_ready]
        ];
    }
}