<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2026 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace BackQ\Adapter;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * The adapter contract
 *
 * Every adapter is handed a logger and answers the eleven methods below. The methods
 * are the contract a worker and a publisher program to, so their names and signatures
 * do not move.
 *
 * The eleven split into the two roles they serve, QueueConsumer and QueueProducer, and
 * this class implements both. That split is what lets a user write an adapter against
 * the four methods of the role it actually is; the eleven stay here for the two
 * consumers that name this class.
 *
 * ## The failure policy
 *
 * A transport or storage failure is the same event for every adapter, so it is resolved
 * in one place, on this class, and not at each call site. Each row is one helper:
 *
 * | Operation                  | Failure            | Not connected or not bound | Helper                  |
 * |----------------------------|--------------------|----------------------------|-------------------------|
 * | `bindRead`, `bindWrite`    | log `error`, false | same as failure            | `attempt()`             |
 * | `afterWork*()`             | log `error`, false | same as failure            | `attempt()`             |
 * | `ping()`, `hasWorkers()`   | log `error`, false | same as failure            | `attempt()`             |
 * | `connect()`                | log `error`, false | no precondition exists     | `attemptConnect()`      |
 * | `putTask()`                | log, return it     | return a `RuntimeException`| `attemptReturning()`    |
 * | `pickTask()`               | log, rethrow       | false                      | `attemptRethrowing()`   |
 *
 * The helpers take a `Closure` and not a callable string, so the adapter's own types
 * stay visible to the static analysers inside the body.
 *
 * Every message is reported through $this?->logger. Psalm reads the nullsafe operator as
 * widening $this to `AbstractAdapter|null` for the rest of the method, which is not what it
 * means on an injected logger, so the two resulting issues are silenced for this class.
 * @psalm-suppress TypeDoesNotContainNull
 * @psalm-suppress PossiblyNullReference
 */
abstract class AbstractAdapter implements QueueConsumer, QueueProducer
{

    /**
     * Connect to server
     */
    abstract public function connect(): bool;

    /**
     * Disconnect from server
     */
    abstract public function disconnect(): bool;

    /**
     * Subscribe to server queue
     *
     * @param string $queue
     */
    #[Override]
    abstract public function bindRead(string $queue): bool;

    /**
     * Prepare to write to server queue
     *
     * @param string $queue
     */
    #[Override]
    abstract public function bindWrite(string $queue): bool;

    /**
     * Get job to process
     *
     * The id is the backend's own: an adapter whose backend reports one as an int may
     * leave it that way, because the two acknowledge methods take a string and their
     * caller casts before invoking them. A payload is a string, an adapter that carries
     * more per job appends it.
     *
     * @param int|null $timeout seconds
     *
     * @return bool|array [id, payload]
     */
    #[Override]
    abstract public function pickTask(?int $timeout = null): bool|array;

    /**
     * Put job to process
     *
     * An adapter may widen this signature by appending its own optional parameters. It may not
     * append a required one, narrow a parameter, or add to the return union: PHP rejects all
     * three as an incompatible declaration.
     *
     * A transport or storage failure is returned as the Throwable rather than raised, so the
     * caller can log it and keep the original type, message and previous chain. An invalid
     * argument is a bug in the caller and is raised.
     *
     * @param string|Stringable $body
     *
     * @return string|Throwable|null the job id, null when this adapter reports no ids,
     *                              the failure otherwise
     */
    #[Override]
    abstract public function putTask(string|Stringable $body): null|string|Throwable;

    /**
     * Acknowledge server: callback after successfully processing job
     *
     * @param string|null $workId the id pickTask() reported, as a string
     */
    #[Override]
    abstract public function afterWorkSuccess(?string $workId): bool;

    /**
     * Acknowledge server: callback after failing to process job
     *
     * @param string|null $workId the id pickTask() reported, as a string
     */
    #[Override]
    abstract public function afterWorkFailed(?string $workId): bool;

    /**
     * Ping if still has alive connection to server
     *
     * @param bool $reconnect
     */
    #[Override]
    abstract public function ping(bool $reconnect = true): bool;

    /**
     * Is there workers ready for job immediately
     *
     * @param string $queue
     */
    #[Override]
    abstract public function hasWorkers(string $queue): bool;

    /**
     * Preffered limit of one work cycle
     * @param int|null $seconds
     */
    abstract public function setWorkTimeout(?int $seconds = null): void;

    /**
     * @param LoggerInterface $logger the logger that receives the adapter messages
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * Is this adapter in a position to talk to the queue
     *
     * True where there is nothing to check, which is the case for an adapter handed an
     * already established link it does not own. The adapters that open a connection
     * themselves answer the question they actually have.
     */
    protected function isReady(): bool
    {
        return true;
    }

    /**
     * Report an operation refused because the adapter is not connected or not bound
     *
     * A `debug` record by default: the caller asked for an operation the adapter cannot
     * perform, and the caller's own start-up path logs the consequence. An adapter that
     * wants the fact to be louder says so here.
     */
    protected function preconditionFailed(string $operation): void
    {
        $this?->logger->debug(static::class . ' adapter ' . $operation . ': not connected or not bound');
    }

    /**
     * A boolean operation that needs a live connection
     *
     * Returns what the operation reported, and false when it could not be performed at
     * all. Never throws: an operation whose failure is a returned value cannot also
     * report it by throwing.
     *
     * @param Closure():bool $body what the operation does, answering true when the queue
     *                             was told
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    protected function attempt(string $operation, Closure $body): bool
    {
        if (!$this->isReady()) {
            $this->preconditionFailed($operation);

            return false;
        }

        return $this->report($operation, $body);
    }

    /**
     * The connect operation, the one with no precondition to check
     *
     * @param Closure():bool $body what the operation does, answering true when the
     *                             transport answered
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    protected function attemptConnect(string $operation, Closure $body): bool
    {
        return $this->report($operation, $body);
    }

    /**
     * An operation whose failure is returned to the caller rather than raised
     *
     * The id on success, the failure otherwise, so the caller keeps the original type,
     * message and previous chain. A missing precondition answers a RuntimeException, as
     * a failure does, because the signature admits no other failure.
     *
     * @param Closure():string $body what the operation does, answering the job id
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    protected function attemptReturning(string $operation, Closure $body): string|Throwable
    {
        if (!$this->isReady()) {
            $this->preconditionFailed($operation);

            return new RuntimeException(static::class . ' adapter ' . $operation . ': not connected to a bound queue');
        }

        try {
            return $body();
        } catch (Throwable $e) {
            $this->log($operation, $e);
        }

        return new RuntimeException(static::class . ' adapter ' . $operation . ': failed');
    }

    /**
     * The pick operation, the one whose failure propagates
     *
     * A worker reads a false pick as an idle cycle, so a transport failure here must not
     * look like one: it is logged and rethrown, and the worker decides what it means.
     *
     * @param Closure():mixed $body what the operation does, answering the job or false
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    protected function attemptRethrowing(string $operation, Closure $body): mixed
    {
        if (!$this->isReady()) {
            $this->preconditionFailed($operation);

            return false;
        }

        try {
            return $body();
        } catch (Throwable $e) {
            $this->log($operation, $e);

            throw $e;
        }
    }

    /**
     * The one log line an adapter writes when the transport or the storage failed
     *
     * The exception goes into the PSR-3 context, so the class, the stack and the previous
     * chain survive to whoever reads the record. The message keeps the text the adapters
     * have always logged, carrying the concrete class rather than this one.
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    protected function log(string $operation, Throwable $e): void
    {
        $this?->logger->error(
            static::class . ' adapter ' . $operation . ' exception: ' . $e->getMessage(),
            ['exception' => $e]
        );
    }

    /**
     * Run one operation under the policy, and answer true only when it reported true
     *
     * @param Closure():bool $body
     *
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    private function report(string $operation, Closure $body): bool
    {
        try {
            return true === $body();
        } catch (Throwable $e) {
            $this->log($operation, $e);
        }

        return false;
    }
}
