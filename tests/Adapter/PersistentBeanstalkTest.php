<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\Beanstalk;
use BackQ\Adapter\Beanstalk\Client;
use BackQ\Adapter\Beanstalk\Connection;
use BackQ\Adapter\PersistentBeanstalk;
use BackQ\Tests\Support\FakeBeanstalkServer;
use BackQ\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use function array_column;
use function fclose;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

class PersistentBeanstalkTest extends TestCase
{
    public function testTheConnectionIsPersistentWhateverTheCallerAskedFor(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk(new RecordingLogger());

            $this->assertTrue($adapter->connect(new Connection(port: $server->getPort())));
            $server->accept();

            $this->assertTrue($this->isPersistent($adapter));
        } finally {
            $server->close();
        }
    }

    public function testTheSocketItselfIsPersistent(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk(new RecordingLogger());

            $this->assertTrue($adapter->connect(new Connection(port: $server->getPort())));
            $server->accept();

            $client = (new ReflectionProperty(Beanstalk::class, 'client'))->getValue($adapter);
            $config = (new ReflectionProperty(Client::class, '_config'))->getValue($client);

            $this->assertTrue($config['persistent']);
        } finally {
            $server->close();
        }
    }

    /**
     * The plain adapter is the one that closes, so the difference between the two is the
     * class and not a flag passed to connect()
     */
    public function testAPlainAdapterIsNeverPersistent(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new Beanstalk(new RecordingLogger());

            $this->assertTrue($adapter->connect(new Connection(port: $server->getPort())));
            $server->accept();

            $client = (new ReflectionProperty(Beanstalk::class, 'client'))->getValue($adapter);
            $config = (new ReflectionProperty(Client::class, '_config'))->getValue($client);

            $this->assertFalse($config['persistent']);
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
        $adapter = new PersistentBeanstalk(new RecordingLogger());

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

    public function testConnectKeepsTheModeOfTheLiveConnection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('connect');

        $adapter = $this->connectedAdapter($client, true);

        /**
         * connect() answers for a socket that is already open, so it does not go through
         * the hook that would decide the mode again
         */
        $this->assertTrue($adapter->connect(new Connection(port: $this->unusedPort())));
        $this->assertTrue($this->isPersistent($adapter));
    }

    public function testConnectLogsThroughTheInjectedLogger(): void
    {
        $logger  = new RecordingLogger();
        $adapter = new PersistentBeanstalk($logger);

        $this->assertFalse($adapter->connect(new Connection(port: $this->unusedPort())));

        $this->assertNotEmpty($logger->records);
        $this->assertContains('error', array_column($logger->records, 0));
    }

    public function testTheInjectedLoggerIsAlsoTheClientsLogger(): void
    {
        /**
         * The vendored client reports through whatever answers error(string), so the
         * adapter hands it the logger it was given. There is no second logger to
         * prefer: connect() no longer takes one.
         */
        $logger  = new RecordingLogger();
        $adapter = new PersistentBeanstalk($logger);

        $this->assertFalse($adapter->connect(new Connection(port: $this->unusedPort())));

        $client = (new ReflectionProperty(Beanstalk::class, 'client'))->getValue($adapter);
        $config = (new ReflectionProperty(Client::class, '_config'))->getValue($client);

        $this->assertSame($logger, $config['logger']);
    }

    public function testAWorkerRunOverAPersistentConnectionKeepsItOpen(): void
    {
        $server = new FakeBeanstalkServer();
        try {
            $adapter = new PersistentBeanstalk(new RecordingLogger());
            $adapter->connect(new Connection(port: $server->getPort()));
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
        $adapter = new PersistentBeanstalk(new RecordingLogger());
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
