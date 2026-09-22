<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Beanstalk;
use BackQ\Adapter\Beanstalk\Client;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function fclose;

class BeanstalkAdapterTest extends TestCase
{
    private function adapterWithConnectedClient(): array
    {
        $adapter = new Beanstalk();
        $client  = $this->createMock(Client::class);

        $clientProp  = new ReflectionProperty(Beanstalk::class, 'client');
        $clientProp->setValue($adapter, $client);

        $connectedProp = new ReflectionProperty(Beanstalk::class, 'connected');
        $connectedProp->setValue($adapter, true);

        return [$adapter, $client];
    }

    public function testConnectWhenAlreadyConnectedReturnsTrue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();

        $client->expects($this->never())->method('connect');

        $this->assertTrue($adapter->connect('127.0.0.1', 11300));
    }

    public function testBindReadDelegatesToWatch(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('watch')->with('tube')->willReturn(2);

        $this->assertTrue($adapter->bindRead('tube'));
    }

    public function testBindWriteDelegatesToUseTube(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('useTube')->with('tube')->willReturn('tube');

        $this->assertTrue($adapter->bindWrite('tube'));
    }

    public function testPutTaskDelegatesToPutWithDefaults(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('put')->with(1024, 0, 60, 'body')->willReturn(42);

        $this->assertSame('42', $adapter->putTask('body'));
    }

    public function testPutTaskForwardsParams(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('put')
            ->with(1, 2, 3, 'body')
            ->willReturn(42);

        $params = [
            Beanstalk::PARAM_PRIORITY  => 1,
            Beanstalk::PARAM_READYWAIT => 2,
            Beanstalk::PARAM_JOBTTR    => 3,
        ];
        $result = $adapter->putTask('body', $params);
        $this->assertSame('42', $result);
    }

    public function testPutTaskReturnsFalseOnFailure(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('put')->willReturn(false);

        $this->assertFalse($adapter->putTask('body'));
    }

    public function testPickTaskReturnsWrappedJob(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('reserve')->with(null)->willReturn(['id' => 1, 'body' => 'payload']);

        $this->assertSame([1, 'payload', []], $adapter->pickTask());
    }

    public function testPickTaskRespectsWorkTimeout(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('reserve')->with(5)->willReturn(['id' => 1, 'body' => 'payload']);

        $adapter->setWorkTimeout(5);

        $this->assertSame([1, 'payload', []], $adapter->pickTask());
    }

    public function testPickTaskReturnsFalseWhenNoJob(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('reserve')->willReturn(false);

        $this->assertFalse($adapter->pickTask());
    }

    public function testPickTasksReturnsCollectedJobs(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->exactly(3))
            ->method('reserve')
            ->with(0)
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'body' => 'a'],
                ['id' => 2, 'body' => 'b'],
                false
            );

        $this->assertSame([[1, 'a'], [2, 'b']], $adapter->pickTasks(5, 0));
    }

    public function testAfterWorkSuccessDelegatesToDelete(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('delete')->with(1)->willReturn(true);

        $this->assertTrue($adapter->afterWorkSuccess(1));
    }

    public function testAfterWorkFailedDelegatesToRelease(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('release')->with(1, 1024, 1)->willReturn(true);

        $this->assertTrue($adapter->afterWorkFailed(1));
    }

    public function testHasWorkersReturnsWatchingCountForQueue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('statsTube')
            ->with('tube')
            ->willReturn(['current-watching' => 2]);

        $this->assertSame(2, $adapter->hasWorkers('tube'));
    }

    public function testHasWorkersReturnsWorkerCountWithoutQueue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('stats')->willReturn(['current-workers' => 3]);

        $this->assertSame(3, $adapter->hasWorkers());
    }

    public function testPingDelegatesToStats(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('stats')->willReturn(['version' => '1.12']);

        $this->assertTrue($adapter->ping());
    }

    public function testDisconnectForwardsToClient(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('disconnect');

        $this->assertTrue($adapter->disconnect());
    }

    public function testDisconnectedInstanceGuards(): void
    {
        $adapter = new Beanstalk();
        $adapter->setTriggerErrorOnError(false);
        $this->assertFalse($adapter->putTask('body'));
        $this->assertFalse($adapter->pickTask());
        $this->assertFalse($adapter->pickTasks(1, 0));
        $this->assertFalse($adapter->afterWorkSuccess(1));
        $this->assertFalse($adapter->afterWorkFailed(1));
        $this->assertFalse($adapter->bindWrite('tube'));
        $this->assertFalse($adapter->bindRead('tube'));
        $this->assertNull($adapter->hasWorkers('tube'));
        $this->assertFalse($adapter->disconnect());
        $this->assertFalse($adapter->connect('127.0.0.1', 1));
    }

    public function testConnectFailureReturnsFalseWhenPeerIsDown(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        fclose($server);

        $adapter = new Beanstalk();
        $adapter->setTriggerErrorOnError(false);

        $this->assertFalse($adapter->connect('127.0.0.1', $port));
    }

    public function testErrorPathLogsWithoutWarningWhenDisabled(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $adapter->setTriggerErrorOnError(false);
        $client
            ->expects($this->once())
            ->method('statsTube')
            ->with('tube')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertNull($adapter->hasWorkers('tube'));
    }
}
