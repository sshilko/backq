<?php
/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter\Amazon\DynamoDb;

use function crc32;
use function json_decode;
use function json_encode;
use function uniqid;

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

    public function toArray(): array
    {
        return ['id'         => [self::DYNAMODB_TYPE_STRING => $this->id],
            'metadata'   => [self::DYNAMODB_TYPE_STRING => json_encode($this->metadata)],
            'payload'    => [self::DYNAMODB_TYPE_STRING => $this->payload],
            'time_ready' => [self::DYNAMODB_TYPE_NUMBER => $this->time_ready],
        ];
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    private function calculateHMAC(): int
    {
        return crc32($this->payload);
    }
}
