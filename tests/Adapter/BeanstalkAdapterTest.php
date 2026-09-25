<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Beanstalk;
use BackQ\Adapter\Beanstalk\Client;
use BackQ\Tests\Support\FakeBeanstalkServer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use function fclose;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use const E_USER_WARNING;

class BeanstalkAdapterTest extends TestCase
{
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
            Beanstalk::PARAM_JOBTTR    => 3,
            Beanstalk::PARAM_PRIORITY  => 1,
            Beanstalk::PARAM_READYWAIT => 2,
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

    public function testHasWorkersTrueWhenWorkersWatchingQueue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('statsTube')
            ->with('tube')
            ->willReturn(['current-watching' => 2]);

        $this->assertTrue($adapter->hasWorkers('tube'));
    }

    public function testHasWorkersFalseWhenNoWorkersWatchingQueue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('statsTube')
            ->with('tube')
            ->willReturn(['current-watching' => 0]);

        $this->assertFalse($adapter->hasWorkers('tube'));
    }

    public function testHasWorkersTrueWhenWorkersConnectedWithoutQueue(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())->method('stats')->willReturn(['current-workers' => 3]);

        $this->assertTrue($adapter->hasWorkers());
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
        $this->assertFalse($adapter->afterWorkSuccess(1));
        $this->assertFalse($adapter->afterWorkFailed(1));
        $this->assertFalse($adapter->bindWrite('tube'));
        $this->assertFalse($adapter->bindRead('tube'));
        $this->assertFalse($adapter->hasWorkers('tube'));
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

    public function testConnectLogsExceptionWhenErrorHandlerThrows(): void
    {
        $adapter = new Beanstalk();
        set_error_handler(static function (): bool {
            throw new RuntimeException('error handler boom');
        }, E_USER_WARNING);

        $message = null;
        try {
            $adapter->connect('127.0.0.1', 1);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            restore_error_handler();
        }

        $this->assertSame('error handler boom', $message);
    }

    public function testConnectSucceedsAgainstFakeServer(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new Beanstalk();
            $adapter->setTriggerErrorOnError(false);

            $this->assertTrue($adapter->connect('127.0.0.1', $server->getPort()));
            $server->accept();
        } finally {
            $server->close();
        }
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

        $this->assertFalse($adapter->hasWorkers('tube'));
    }

    public function testPingReconnectsWhenStatsReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->method('stats')
            ->willReturnOnConsecutiveCalls(false, ['version' => '1.12']);
        $client->method('connect')->willReturn(true);

        $this->assertTrue($adapter->ping());
    }

    public function testPingReturnsFalseWhenReconnectAlsoFails(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->method('stats')->willReturn(false);
        $client->method('connect')->willReturn(false);

        $this->assertFalse($adapter->ping());
    }

    public function testPingExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('stats')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->ping());
    }

    public function testBindReadExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('watch')
            ->with('tube')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->bindRead('tube'));
    }

    public function testBindWriteExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('useTube')
            ->with('tube')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->bindWrite('tube'));
    }

    public function testPickTaskPropagatesClientFailure(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client->expects($this->once())
            ->method('reserve')
            ->willThrowException(new RuntimeException('connection lost'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('connection lost');

        $adapter->pickTask();
    }

    public function testPickTaskForwardsTimeoutArgument(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('reserve')
            ->with(7)
            ->willReturn(false);

        $this->assertFalse($adapter->pickTask(7));
    }

    public function testPutTaskExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('put')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->putTask('body'));
    }

    public function testAfterWorkSuccessExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('delete')
            ->with(1)
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->afterWorkSuccess(1));
    }

    public function testAfterWorkFailedExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('release')
            ->with(1, 1024, 1)
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->afterWorkFailed(1));
    }

    public function testDisconnectExceptionReturnsFalse(): void
    {
        [$adapter, $client] = $this->adapterWithConnectedClient();
        $client
            ->expects($this->once())
            ->method('disconnect')
            ->willThrowException(new RuntimeException('boom'));

        $this->assertFalse($adapter->disconnect());
    }

    private function adapterWithConnectedClient(): array
    {
        $adapter = new Beanstalk();
        $adapter->setTriggerErrorOnError(false);
        $client  = $this->createMock(Client::class);

        $clientProp  = new ReflectionProperty(Beanstalk::class, 'client');
        $clientProp->setValue($adapter, $client);

        $connectedProp = new ReflectionProperty(Beanstalk::class, 'connected');
        $connectedProp->setValue($adapter, true);

        return [$adapter, $client];
    }
}
