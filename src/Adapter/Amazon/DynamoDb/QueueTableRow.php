<?php
namespace BackQ\Adapter\Amazon\DynamoDb;

class QueueTableRow
{
    private const DYNAMODB_TYPE_STRING = 'S';
    private const DYNAMODB_TYPE_NUMBER = 'N';

    protected $id;
    protected $payload;
    protected $metadata = [];
    protected $time_ready;

    public function __construct(string $body, int $timeReady, string $queueId = "")
    {
        $this->id         = uniqid($queueId . '.', false);
        $this->payload    = $body;
        $this->time_ready = (string) $timeReady;
        $this->metadata['payload_checksum'] = $this->calculateHMAC();
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

    public function getPayload(): string
    {
        return $this->payload;
    }

    public static function fromArray(array $array): ?self
    {
        if (!isset($array['id'], $array['metadata'], $array['payload'], $array['time_ready'])) {
            return null;
        }

        $item     = new self($array['payload'], $array['time_ready']);
        $metadata = json_decode($array['metadata'], true);

        /**
         * Verify that the payload checksum corresponds to the payload
         */
        if ($metadata['payload_checksum'] !== $item->calculateHMAC()) {
            return null;
        }
        return $item;
    }
}