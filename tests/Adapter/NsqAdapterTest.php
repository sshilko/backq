<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Nsq;
use PHPUnit\Framework\TestCase;
use Throwable;
use function fclose;
use function fsockopen;
use function getenv;
use function is_array;
use function sprintf;
use function time;
use function uniqid;

/**
 * Integration test against a real nsqd server.
 *
 * Skips when nsqd is unreachable, so the plain host-side `composer app-tests`
 * run stays green without docker.
 */
class NsqAdapterTest extends TestCase
{
    private const string DEFAULT_HOST = 'nsq';

    private const int DEFAULT_PORT = 4150;

    public function testPublisherToConsumerFlow(): void
    {
        $host = getenv('BACKQ_NSQD_HOST') ?: self::DEFAULT_HOST;
        $port = (int) (getenv('BACKQ_NSQD_PORT') ?: self::DEFAULT_PORT);

        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (false === $sock) {
            self::markTestSkipped(sprintf('nsqd not reachable at %s:%d', $host, $port));
        }
        fclose($sock);

        $queue = 'backq.test.' . uniqid();
        $body  = 'hello-from-tests-' . uniqid();

        $publisher = new Nsq($host, $port);
        $this->assertTrue($publisher->connect());
        $this->assertTrue($publisher->bindWrite($queue));
        $this->assertNull($publisher->putTask($body));
        $publisher->disconnect();

        $consumer = new Nsq($host, $port);
        $this->assertTrue($consumer->connect());
        $this->assertTrue($consumer->bindRead($queue));

        $task = $this->pickTaskUntilMessageReceived($consumer);

        if (is_array($task)) {
            [$id, $message,] = $task;
            $this->assertSame($body, $message);
            $this->assertTrue($consumer->afterWorkSuccess($id));
        } else {
            $this->fail('Failed to pick published task from nsqd');
        }

        $consumer->disconnect();
    }

    /**
     * @return array{0:string,1:string,2:array}|false
     */
    private function pickTaskUntilMessageReceived(Nsq $consumer): bool|array
    {
        $deadline = time() + 5;

        while (time() < $deadline) {
            try {
                $task = $consumer->pickTask();
            } catch (Throwable) {
                $task = false;
            }

            if (is_array($task) && '' !== $task[0]) {
                return $task;
            }
        }

        return false;
    }
}
