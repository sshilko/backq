<?php
namespace BackQ\Worker;

class Closure extends AbstractWorker
{
    /**
     * @var string
     */
    protected $queueName = 'closure';

    /**
     * @var int
     */
    public $workTimeout = 5;

    public function run()
    {
        $connected = $this->start();
        $this->debug('started');

        if ($connected) {
            try {
                $this->logInfo('before init work generator');

                $work = $this->work();
                $this->logInfo('after init work generator');

                foreach ($work as $taskId => $payload) {
                    $this->logInfo(time() . ' got some work: ' . ($payload ? 'yes' : 'no'));
                    if (!$payload) {
                        $work->send(true);
                        continue;
                    }

                    $message = @unserialize($payload);
                    if (!($message instanceof \BackQ\Message\Closure)) {
                        $work->send(true);
                        $this->logError('Closure worker does not support payload of: ' . gettype($message));
                        continue;
                    }

                    if (!$message->isReady()) {
                        /**
                         * Message should not be processed now
                         */
                        $work->send(false);
                        continue;
                    }

                    if ($message->isExpired()) {
                        $work->send(true);
                        continue;
                    }

                    try {
                        $message->execute();
                    } catch (\Throwable $e) {
                        $this->logError('Error executing closure ' . $e->getMessage());
                    }
                    $work->send(true);
                }
            } catch (\Exception $e) {
                $this->logError($e->getMessage());
            }
        }
        $this->finish();
    }
}
