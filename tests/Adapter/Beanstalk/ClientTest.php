<?php

namespace BackQ\Tests\Adapter\Beanstalk;

use BackQ\Adapter\Beanstalk\Client;
use BackQ\Adapter\IO\Exception\RuntimeException;
use BackQ\Tests\Support\FakeBeanstalkServer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use function strlen;
use function uniqid;

class ClientTest extends TestCase
{

    private FakeBeanstalkServer $server;

    public function testConfigDefaultsAreApplied(): void
    {
        $client = new Client();

        $this->assertSame([
            'host' => '127.0.0.1',
            'logger' => null,
            'persistent' => true,
            'port' => 11300,
            'timeout' => 1,
        ], $this->config($client));
    }

    public function testConfigValuesCanBeOverridden(): void
    {
        $client = new Client(['host' => '10.0.0.1', 'port' => 12345, 'timeout' => 5, 'persistent' => false]);

        $this->assertSame([
            'host' => '10.0.0.1',
            'logger' => null,
            'persistent' => false,
            'port' => 12345,
            'timeout' => 5,
        ], $this->config($client));
    }

    public function testDisconnectOnNeverConnectedClientReturnsFalse(): void
    {
        $client = new Client(['persistent' => false]);

        $this->assertFalse($client->disconnect());
    }

    public function testCommandsThrowWhenNotConnected(): void
    {
        $client = new Client();

        $this->expectException(RuntimeException::class);

        $client->stats();
    }

    public function testConnectSucceeds(): void
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => $this->server->getPort(), 'persistent' => false]);

        $this->assertTrue($client->connect());
        $this->assertTrue($client->connected);
        $this->server->accept();

        $client->disconnect();
    }

    public function testUseTubeRoundTrip(): void
    {
        $client = $this->connectClient();
        $tube   = 'backq.test.' . uniqid();

        $this->server->queueResponse("USING $tube\r\n");

        $this->assertSame($tube, $client->useTube($tube));
        $this->assertSame("use $tube\r\n", $this->server->readRequest(strlen("use $tube\r\n")));

        $client->disconnect();
    }

    public function testWatchRoundTrip(): void
    {
        $client = $this->connectClient();
        $tube   = 'backq.test.' . uniqid();

        $this->server->queueResponse("WATCHING 2\r\n");

        $this->assertSame(2, $client->watch($tube));
        $this->assertSame("watch $tube\r\n", $this->server->readRequest(strlen("watch $tube\r\n")));

        $client->disconnect();
    }

    public function testPutRoundTrip(): void
    {
        $client = $this->connectClient();
        $body   = 'hello';

        $this->server->queueResponse("INSERTED 42\r\n");

        $this->assertSame(42, $client->put(1024, 0, 60, $body));
        $this->assertSame(
            "put 1024 0 60 5\r\nhello\r\n",
            $this->server->readRequest(strlen("put 1024 0 60 5\r\nhello\r\n"))
        );

        $client->disconnect();
    }

    public function testPutReturnsBuriedJobId(): void
    {
        $client = $this->connectClient();

        $this->server->queueResponse("BURIED 1\r\n");

        $this->assertSame(1, $client->put(1024, 0, 60, 'hello'));

        $client->disconnect();
    }

    public function testReserveRoundTrip(): void
    {
        $client = $this->connectClient();

        $this->server->queueResponse("RESERVED 7 5\r\nhello\r\n");

        $reserved = $client->reserve(5);
        $this->assertIsArray($reserved);
        $this->assertSame(7, $reserved['id']);
        $this->assertSame('hello', $reserved['body']);

        $client->disconnect();
    }

    public function testReserveWithTimeoutReturnsFalseOnTimeout(): void
    {
        $client = $this->connectClient();

        $this->server->queueResponse("TIMED_OUT\r\n");

        $this->assertFalse($client->reserve(1));

        $client->disconnect();
    }

    public function testDeleteRoundTrip(): void
    {
        $client = $this->connectClient();

        $this->server->queueResponse("DELETED\r\n");

        $this->assertTrue($client->delete(42));
        $this->assertSame("delete 42\r\n", $this->server->readRequest(11));

        $client->disconnect();
    }

    public function testReleaseRoundTrip(): void
    {
        $client = $this->connectClient();

        $this->server->queueResponse("RELEASED\r\n");

        $this->assertTrue($client->release(42, 1024, 0));

        $client->disconnect();
    }

    public function testStatsRoundTrip(): void
    {
        $client  = $this->connectClient();
        $payload = ['version' => '1.12', 'current-workers' => '4'];
        $this->server->queueStats($payload);

        $stats = $client->stats();
        $this->assertIsArray($stats);
        $this->assertSame(1.12, $stats['version']);
        $this->assertSame(4, $stats['current-workers']);

        $client->disconnect();
    }

    public function testStatsTubeRoundTrip(): void
    {
        $client  = $this->connectClient();
        $tube    = 'backq.test.' . uniqid();
        $payload = ['name' => $tube, 'current-watching' => 3];
        $this->server->queueStats($payload);

        $stats = $client->statsTube($tube);
        $this->assertIsArray($stats);
        $this->assertSame($tube, $stats['name']);
        $this->assertSame(3, $stats['current-watching']);

        $client->disconnect();
    }

    public function testDisconnectWritesQuit(): void
    {
        $client = $this->connectClient();

        $this->assertFalse($client->disconnect());
        $this->assertFalse($client->connected);
        $this->assertSame("quit\r\n", $this->server->readRequest(6));
    }

    public function testProtocolRoundTrip(): void
    {
        $tube = 'backq.test.' . uniqid();

        $producer = $this->connectClient();
        $this->server->queueResponse("USING $tube\r\n");
        $this->assertSame($tube, $producer->useTube($tube));

        $this->server->queueResponse("INSERTED 1\r\n");
        $this->assertSame(1, $producer->put(1024, 0, 60, 'payload'));

        $worker = $this->connectClient();
        $this->server->queueResponse("WATCHING 2\r\n");
        $this->assertSame(2, $worker->watch($tube));

        $this->server->queueResponse("RESERVED 1 7\r\npayload\r\n");
        $reserved = $worker->reserve(5);
        $this->assertIsArray($reserved);
        $this->assertSame(1, $reserved['id']);
        $this->assertSame('payload', $reserved['body']);

        $this->server->queueResponse("DELETED\r\n");
        $this->assertTrue($worker->delete(1));

        $producer->disconnect();
        $worker->disconnect();
    }

    protected function setUp(): void
    {
        $this->server = new FakeBeanstalkServer();
    }

    protected function tearDown(): void
    {
        $this->server->close();
    }

    private function connectClient(): Client
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => $this->server->getPort(), 'persistent' => false]);
        $this->assertTrue($client->connect());
        $this->server->accept();

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Client $client): array
    {
        $property = new ReflectionProperty(Client::class, '_config');

        return $property->getValue($client);
    }
}
