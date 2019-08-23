<?php
namespace BackQ\Worker;

use BackQ\Message\AbstractMessage;
use BackQ\Publisher\AbstractPublisher;

final class Serialized extends AbstractWorker
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    public $workTimeout = 5;

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

                    if (!($message instanceof \BackQ\Message\Serialized)) {
                        $work->send(true);
                        if ($this->logger) {
                            $this->logger->error('Worker does not support payload of: ' . gettype($message));
                        }
                        continue;
                    }
                    $originalPublisher = $message->getPublisher();
                    $originalMessage   = $message->getMessage();
                    $originalPubOpts   = $message->getPublishOptions();

                    if ($originalPublisher && $originalMessage) {
                        $processed = false;
                        try {
                            if ($this->dispatchOriginalMessage($originalPublisher,
                                                               $originalMessage,
                                                               $originalPubOpts)) {
                                $processed = true;
                            }
                        } catch (\Exception $ex) {
                            if ($this->logger) {
                                $this->logger->error($ex->getMessage());
                            }
                        }
                    } else {
                        if ($this->logger) {
                            if (!$originalMessage) {
                                $this->logger->error('Missing original message');
                            }
                            if (!$originalPublisher) {
                                $this->logger->error('Missing original publisher');
                            }
                        }

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

    /**
     * @param AbstractPublisher $publisher
     * @param AbstractMessage $message
     * @param array $publishOptions
     * @return string|null
     */
    private function dispatchOriginalMessage(AbstractPublisher $publisher,
                                             AbstractMessage $message,
                                             array $publishOptions = []): ?string
    {
        if ($publisher->start()) {
            return (string) $publisher->publish($message, $publishOptions);
        }
        return null;
    }
}
