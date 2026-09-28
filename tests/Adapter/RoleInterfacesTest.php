<?php

namespace BackQ\Tests\Adapter;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Adapter\QueueConsumer;
use BackQ\Adapter\QueueProducer;
use BackQ\Tests\Support\TestAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Throwable;
use function array_filter;
use function array_map;
use function array_values;
use function method_exists;
use function sort;

/**
 * The two roles the eleven methods of AbstractAdapter split into
 *
 * These are the checks that do not need a queue: that the split happened, that a role is
 * implementable without the abstract class, and that the contract the consumers program to
 * is still the same eleven methods.
 */
class RoleInterfacesTest extends TestCase
{
    public function testEveryAdapterIsBothARole(): void
    {
        $adapter = new TestAdapter();

        $this->assertInstanceOf(QueueConsumer::class, $adapter);
        $this->assertInstanceOf(QueueProducer::class, $adapter);
    }

    public function testTheConsumerRoleIsTheFourMethodsAWorkerNeeds(): void
    {
        $this->assertSame(
            ['afterWorkFailed', 'afterWorkSuccess', 'bindRead', 'pickTask'],
            $this->methodNames(QueueConsumer::class)
        );
    }

    public function testTheProducerRoleIsTheFourMethodsAPublisherNeeds(): void
    {
        $this->assertSame(
            ['bindWrite', 'hasWorkers', 'ping', 'putTask'],
            $this->methodNames(QueueProducer::class)
        );
    }

    /**
     * Both consumers call connect() and disconnect(), and the worker calls setWorkTimeout(),
     * so a role that declared them would be a lie about how small it is
     */
    public function testNeitherRoleCarriesTheMethodsBothConsumersNeed(): void
    {
        foreach ([QueueConsumer::class, QueueProducer::class] as $role) {
            $this->assertFalse(method_exists($role, 'connect'), $role . ' must not declare connect()');
            $this->assertFalse(method_exists($role, 'disconnect'), $role . ' must not declare disconnect()');
            $this->assertFalse(method_exists($role, 'setWorkTimeout'), $role . ' must not declare setWorkTimeout()');
        }
    }

    /**
     * The point of the split: a small adapter is four methods, not eleven
     */
    public function testAConsumerIsImplementableWithoutTheAbstractClass(): void
    {
        $consumer = new class implements QueueConsumer {

            public bool $picked = false;

            public function bindRead(string $queue): bool
            {
                return true;
            }

            public function pickTask(?int $timeout = null): bool|array
            {
                $this->picked = true;

                return ['id', 'payload'];
            }

            public function afterWorkSuccess(?string $workId): bool
            {
                return true;
            }

            public function afterWorkFailed(?string $workId): bool
            {
                return true;
            }
        };

        $this->assertTrue($consumer->bindRead('tube'));
        $this->assertSame(['id', 'payload'], $consumer->pickTask());
        $this->assertTrue($consumer->afterWorkSuccess('id'));
        $this->assertTrue($consumer->afterWorkFailed('id'));
    }

    public function testAProducerIsImplementableWithoutTheAbstractClass(): void
    {
        $producer = new class implements QueueProducer {

            public null|string|Throwable $put = 'job-id';

            public function bindWrite(string $queue): bool
            {
                return true;
            }

            public function putTask(string|Stringable $body): null|string|Throwable
            {
                return $this->put;
            }

            public function hasWorkers(string $queue): bool
            {
                return true;
            }

            public function ping(bool $reconnect = true): bool
            {
                return true;
            }
        };

        $this->assertTrue($producer->bindWrite('tube'));
        $this->assertSame('job-id', $producer->putTask('body'));
        $this->assertTrue($producer->hasWorkers('tube'));
        $this->assertTrue($producer->ping());
    }

    public function testTheContractIsStillTheElevenMethodsTheConsumersProgramTo(): void
    {
        $abstract = array_values(array_filter(
            array_map(
                static function (ReflectionMethod $method): ?string {
                    return $method->isAbstract() ? $method->getName() : null;
                },
                (new ReflectionClass(AbstractAdapter::class))->getMethods()
            )
        ));

        sort($abstract);

        $this->assertSame([
            'afterWorkFailed',
            'afterWorkSuccess',
            'bindRead',
            'bindWrite',
            'connect',
            'disconnect',
            'hasWorkers',
            'pickTask',
            'ping',
            'putTask',
            'setWorkTimeout',
        ], $abstract);
    }

    /**
     * @param class-string $role
     *
     * @return array<string>
     */
    private function methodNames(string $role): array
    {
        $names = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass($role))->getMethods()
        );
        sort($names);

        return $names;
    }
}
