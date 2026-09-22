<?php

namespace BackQ\Tests\Support;

use BackQ\Adapter\AbstractAdapter;
use BackQ\Worker\AbstractWorker;
use Override;

/**
 * Bounded worker used to exercise AbstractWorker::work() as a generator.
 */
class TestWorker extends AbstractWorker
{
    public ?int $workTimeout = 5;

    protected $queueName = 'testqueue';

    public array $yields   = [];
    public array $responses;
    private int $index = 0;

    public function __construct(AbstractAdapter $adapter, array $responses = [true])
    {
        $this->responses = $responses;
        parent::__construct($adapter);
    }

    public function doStart(): bool
    {
        return $this->start();
    }

    public function doFinish(): bool
    {
        return $this->finish();
    }

    #[Override]
    public function run(): void
    {
        $connected = $this->start();
        if ($connected) {
            $work = $this->work();
            foreach ($work as $id => $payload) {
                $this->yields[] = [$id, $payload];
                $response       = $this->responses[$this->index] ?? true;
                $this->index++;
                $work->send($response);
            }
        }
        $this->finish();
    }
}