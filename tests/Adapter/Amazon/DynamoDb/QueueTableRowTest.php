<?php

namespace BackQ\Tests\Adapter\Amazon\DynamoDb;

use BackQ\Adapter\Amazon\DynamoDb\QueueTableRow;
use PHPUnit\Framework\TestCase;
use function array_key_first;
use function crc32;
use function json_decode;
use function json_encode;

class QueueTableRowTest extends TestCase
{
    public function testToArrayStructure(): void
    {
        $row   = new QueueTableRow('payload', 12345, 'q1');
        $array = $row->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertArrayHasKey('payload', $array);
        $this->assertArrayHasKey('time_ready', $array);

        $this->assertSame('S', array_key_first($array['id']));
        $this->assertSame('S', array_key_first($array['metadata']));
        $this->assertSame('S', array_key_first($array['payload']));
        $this->assertSame('N', array_key_first($array['time_ready']));

        $this->assertSame('payload', $array['payload']['S']);
        $this->assertSame('12345', $array['time_ready']['N']);

        $id = $array['id']['S'];
        $this->assertStringStartsWith('q1.', $id);

        $metadata = json_decode($array['metadata']['S'], true);
        $this->assertSame(crc32('payload'), $metadata['payload_checksum']);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new QueueTableRow('job-body', 60, 'q');
        $plain    = [
            'id'         => $original->toArray()['id']['S'],
            'metadata'   => json_encode(['payload_checksum' => crc32('job-body')]),
            'payload'    => 'job-body',
            'time_ready' => 60,
        ];

        $row = QueueTableRow::fromArray($plain);

        $this->assertNotNull($row);
        $this->assertSame('job-body', $row->getPayload());
    }

    public function testFromArrayMissingKeysReturnsNull(): void
    {
        $this->assertNull(QueueTableRow::fromArray(['id' => 'x']));
        $this->assertNull(QueueTableRow::fromArray([]));
    }

    public function testFromArrayWithCorruptedPayloadReturnsNull(): void
    {
        $plain = [
            'id'         => 'id',
            'metadata'   => json_encode(['payload_checksum' => crc32('original')]),
            'payload'    => 'tampered',
            'time_ready' => 60,
        ];

        $this->assertNull(QueueTableRow::fromArray($plain));
    }

    public function testFromArrayPreservesTimeReady(): void
    {
        $plain = [
            'id'         => 'q.1',
            'metadata'   => json_encode(['payload_checksum' => crc32('job-body')]),
            'payload'    => 'job-body',
            'time_ready' => 12345,
        ];

        $row = QueueTableRow::fromArray($plain);

        $this->assertNotNull($row);
        $this->assertSame('12345', $row->toArray()['time_ready']['N']);
    }

    public function testFromArrayAcceptsExtraMetadataKeys(): void
    {
        $plain = [
            'id'         => 'q.1',
            'metadata'   => json_encode(['payload_checksum' => crc32('job-body'), 'extra' => 'meta']),
            'payload'    => 'job-body',
            'time_ready' => 12345,
        ];

        $this->assertNotNull(QueueTableRow::fromArray($plain));
    }

    public function testFromArrayWithMetadataMissingChecksumReturnsNull(): void
    {
        $plain = [
            'id'         => 'q.1',
            'metadata'   => json_encode(['extra' => 'meta']),
            'payload'    => 'job-body',
            'time_ready' => 12345,
        ];

        $this->assertNull(QueueTableRow::fromArray($plain));
    }

    public function testFromArrayWithMalformedMetadataReturnsNull(): void
    {
        $plain = [
            'id'         => 'q.1',
            'metadata'   => 'not-json',
            'payload'    => 'job-body',
            'time_ready' => 12345,
        ];

        $this->assertNull(QueueTableRow::fromArray($plain));
    }
}
