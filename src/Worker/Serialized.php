<?php
namespace BackQ\Worker;

final class Serialized extends AbstractWorker
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    public $workTimeout = 2;

    /**
     * Declare Logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $log)
    {
        $this->logger = $log;
    }

    public function run()
    {
        $connected = $this->start();
        if ($this->logger) {
            $this->logger->info('started');
        }
        $push = null;
        if ($connected) {
            try {
                if ($this->logger) {
                    $this->logger->info('before init work generator');
                }
                $work = $this->work();
                if ($this->logger) {
                    $this->logger->info('after init work generator');
                }

                foreach ($work as $taskId => $payload) {
                    if ($this->logger) {
                        $this->logger->info(time() . ' got some work: ' . ($payload ? 'yes' : 'no'));
                    }

                    if (!$payload && $this->workTimeout > 0) {
                        /**
                         * Just empty loop, no work fetched
                         */
                        $work->send(true);
                        continue;
                    }

                    $message   = @unserialize($payload);
                    $processed = true;

                    print_r($message);

                    if (!($message instanceof \BackQ\Message\Serialized)) {
                        $work->send(true);
                        if ($this->logger) {
                            $this->logger->error('Worker does not support payload of: ' . gettype($message));
                        }
                        continue;
                    }

                    $work->send($processed);

                };
            } catch (\Exception $e) {
                if ($this->logger) {
                    $this->logger->error($e->getMessage());
                }
            }
        }
        $this->finish();
    }
}
