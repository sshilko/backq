<?php

namespace BackQ\Tests\Adapter\Redis;

use BackQ\Adapter\Redis\Connector;
use BackQ\Adapter\Redis\Queue;
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ConnectorTest extends TestCase
{
    public function testConnectReturnsQueueWithConfig(): void
    {
        $redis = $this->createMock(Factory::class);

        $connector = new Connector($redis);

        /** @var Queue $queue */
        $queue = $connector->connect([
            'queue'      => 'backq.test.queue',
            'connection' => 'default',
            'retry_after' => 90,
            'block_for'   => 3,
        ]);

        $this->assertInstanceOf(Queue::class, $queue);

        $blockFor   = (new ReflectionProperty(Queue::class, 'blockFor'))->getValue($queue);
        $retryAfter = (new ReflectionProperty(Queue::class, 'retryAfter'))->getValue($queue);

        $this->assertSame(3, $blockFor);
        $this->assertSame(90, $retryAfter);
    }

    public function testConnectDefaultsWhenValuesMissing(): void
    {
        $redis = $this->createMock(Factory::class);

        $connector = new Connector($redis);

        /** @var Queue $queue */
        $queue = $connector->connect(['queue' => 'default']);

        $this->assertInstanceOf(Queue::class, $queue);

        $blockFor   = (new ReflectionProperty(Queue::class, 'blockFor'))->getValue($queue);
        $retryAfter = (new ReflectionProperty(Queue::class, 'retryAfter'))->getValue($queue);

        $this->assertNull($blockFor);
        $this->assertNull($retryAfter);
    }
}
