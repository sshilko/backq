<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Beanstalk;
use BackQ\Adapter\Beanstalk\Client;
use BackQ\Adapter\PersistentBeanstalk;
use BackQ\Tests\Support\FakeBeanstalkServer;
use BackQ\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use function array_column;
use function fclose;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

class PersistentBeanstalkTest extends TestCase
{
    public function testTheConnectionIsPersistentUnlessAskedOtherwise(): void
    {
        $parameters = (new ReflectionMethod(PersistentBeanstalk::class, 'connect'))->getParameters();

        $this->assertTrue($parameters[3]->getDefaultValue());
    }

    public function testConnectKeepsTheConnectionOpenForTheNextWorker(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk();

            $this->assertTrue($adapter->connect('127.0.0.1', $server->getPort()));
            $server->accept();

            $this->assertTrue($this->isPersistent($adapter));
        } finally {
            $server->close();
        }
    }

    public function testConnectCanAskForANonPersistentConnection(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk();

            $this->assertTrue($adapter->connect('127.0.0.1', $server->getPort(), 1, false));
            $server->accept();

            $this->assertFalse($this->isPersistent($adapter));
        } finally {
            $server->close();
        }
    }

    public function testDisconnectIsANoopWhileTheConnectionIsPersistent(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('disconnect');

        $adapter = $this->connectedAdapter($client, true);

        $this->assertTrue($adapter->disconnect());
    }

    public function testDisconnectClosesANonPersistentConnection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('disconnect');

        $adapter = $this->connectedAdapter($client, false);

        $this->assertTrue($adapter->disconnect());
    }

    public function testDisconnectWithoutAConnectionReturnsFalse(): void
    {
        $adapter = new PersistentBeanstalk();

        $this->assertFalse($adapter->disconnect());
    }

    public function testTheDestructorKeepsAPersistentConnection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('disconnect');

        $this->connectedAdapter($client, true);
        $this->addToAssertionCount(1);
    }

    public function testTheDestructorClosesANonPersistentConnection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('disconnect');

        $this->connectedAdapter($client, false);
        $this->addToAssertionCount(1);
    }

    public function testConnectRemembersTheAskedForModeEvenWhenAlreadyConnected(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('connect');

        $adapter = $this->connectedAdapter($client, true);

        /**
         * The live connection stays open, but the adapter now believes it has to
         * close it once the worker is done
         */
        $this->assertTrue($adapter->connect('127.0.0.1', $this->unusedPort(), 1, false));
        $this->assertFalse($this->isPersistent($adapter));
    }

    public function testConnectLogsThroughTheAdapterWhenNoLoggerIsGiven(): void
    {
        $logger  = new RecordingLogger();
        $adapter = new PersistentBeanstalk();
        $adapter->setLogger($logger);

        $this->assertFalse($adapter->connect('127.0.0.1', $this->unusedPort()));

        $this->assertNotEmpty($logger->records);
        $this->assertContains('error', array_column($logger->records, 0));
    }

    public function testConnectPrefersTheGivenLogger(): void
    {
        $ownLogger    = new RecordingLogger();
        $givenLogger  = new RecordingLogger();
        $adapter      = new PersistentBeanstalk();
        $adapter->setLogger($ownLogger);

        $this->assertFalse($adapter->connect('127.0.0.1', $this->unusedPort(), 1, true, $givenLogger));

        $this->assertNotEmpty($givenLogger->records);
        $this->assertSame([], $ownLogger->records);
    }

    public function testAWorkerRunOverAPersistentConnectionKeepsItOpen(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk();
            $adapter->connect('127.0.0.1', $server->getPort());
            $server->accept();

            /**
             * A worker disconnects the adapter when it is done
             */
            $this->assertTrue($adapter->disconnect());
            $this->assertTrue($this->isPersistent($adapter));
        } finally {
            $server->close();
        }
    }

    private function connectedAdapter(Client $client, bool $persistent): PersistentBeanstalk
    {
        $adapter = new PersistentBeanstalk();
        (new ReflectionProperty(Beanstalk::class, 'client'))->setValue($adapter, $client);
        (new ReflectionProperty(Beanstalk::class, 'connected'))->setValue($adapter, true);
        (new ReflectionProperty(PersistentBeanstalk::class, 'persistentConnection'))->setValue($adapter, $persistent);

        return $adapter;
    }

    private function isPersistent(PersistentBeanstalk $adapter): bool
    {
        return (bool) (new ReflectionProperty(PersistentBeanstalk::class, 'persistentConnection'))->getValue($adapter);
    }

    private function unusedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        fclose($server);

        return $port;
    }
}
