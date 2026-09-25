# Plan 2 — Network & SSL disconnect handling (findings 2.1 – 2.3)

> Status: **implemented** — completed in the current working tree.
>
> **Implementation notes (current checkout):**
> - `src/Worker/Guzzle.php`: rejection handler now catches `Throwable` (not just `RequestException`) and marks transport/connect failures as processed=false (with `error_log` for connect failures); HTTP errors that return a response are logged but not marked as failure. `processed` set to false on catch.
> - `src/Adapter/Nsq.php`: `connect()` rolls back state (`_io=null`, `connected=false`) on handshake failures; `disconnect()` skips graceful CLS/CLOSE_WAIT when socket is broken (`isSocketReady()` true).
> - `src/Adapter/IO/StreamIO.php`: `close()` suppresses shutdown/fclose warnings with `@`.
> - Tests: Guzzle connect-refused failure now expects `afterWorkFailed` and no `afterWorkSuccess`; Nsq connect handshake failures renamed/assert rollback; StreamIO adds `testCloseIsQuietOnDeadSocket`. All targeted tests pass. PHPCS/PHPStan clean on changed files.
> Scope: section 2 of the analysis report (network / SSL disconnects).
> Companion plans: `plan-1-protocol-tcp-frame-handling.md`, `plan-3-connection-liveness-resilience.md`,
> `plan-4-minor-notes-behavior.md`.

## How to execute this plan

Same two-step ritual as Plan 1: **Phase A** (failing tests, must be RED on current code) then
**Phase B** (implementation → tests GREEN). Where an existing test asserts the buggy behavior, the
flip is itself the RED test; run it in Phase A to confirm RED, land the fix in Phase B, and keep the
flipped test.

---

## 2.1 CRITICAL — Guzzle worker acknowledges failed HTTP/SSL sends as success

**Location:** `src/Worker/Guzzle.php`, `run()` (lines ~37–131):

- `$processed = true;` (line 71) — set once, never reassigned.
- `sendAsync(...)->then(fulfill, reject)` (lines 102–113): the rejection handler is typed
  `RequestException` and only logs, returning normally → the promise chain resolves → `wait()` returns.
- `finally { $work->send((true === $processed)); }` (lines 118–124) → always acks **success**.

**Mechanism (two failure modes):**
1. `ConnectException` (connection refused, TLS/SSL handshake failure, DNS failure) is **not** a
   `RequestException` (it extends `TransferException`). The typed rejection callback is invoked with a
   `ConnectException` → PHP `TypeError` inside the promise handler → thrown, caught by the surrounding
   `catch (Throwable $e)` (line 116) → logged – and the `finally` still sends `true`.
2. A real `RequestException` (timeout, http protocol-level error) is handled by the callback, which
   does not rethrow or record failure → `wait()` returns normally → `finally` sends `true`.

Result: the job is **deleted** (ads `afterWorkSuccess`) even though the HTTP/SSL request never
completed. This is the most impactful bug in the report.

**Phase A — failing tests** (`tests/Worker/GuzzleWorkerTest.php`):

Flip the existing test `testLogsConnectRefusedFailure` (lines ~239–264). It already drives a real
`ConnectException` via `http://127.0.0.1:9/` (port 9 is closed on loopback). Change the final
assertions to:

```php
$this->assertStringContainsString('Error while sending FCM', $loggedErrors);
$this->assertContains(['afterWorkFailed', 16], $adapter->calls);
$this->assertNotContains(['afterWorkSuccess', 16], $adapter->calls);
```

Why RED today: the worker records `['afterWorkSuccess', 16]` and no `afterWorkFailed` → both assertions
fail.

Add an SSL-focused variant (TLS handshake against a plain-TCP listener → cURL error 35 →
`ConnectException`), reusing the `pcntl_fork` listener pattern of `testSendsAsyncRequestToLocalServer`
but requesting `https://127.0.0.1:<port>/`:

```php
public function testTlsHandshakeFailureIsAcknowledgedAsFailure(): void
{
    // server accepts but does NOT speak TLS
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $this->assertNotFalse($server, 'unable to open local socket server: ' . $errstr);
    $socketName = stream_socket_get_name($server, false);
    $port       = (int) substr($socketName, strrpos($socketName, ':') + 1);

    $pid = pcntl_fork();
    $this->assertNotSame(-1, $pid, 'pcntl_fork() failed');
    if (0 === $pid) {
        $connection = stream_socket_accept($server, 30);
        if (false !== $connection) {
            while (fgets($connection)) {
                // swallow whatever the TLS client sends, never respond
                usleep(1000);
            }
        }
        fclose($server);
        exit(0);
    }

    try {
        $adapter                = new TestAdapter();
        $adapter->pickTaskResult = [19, serialize(new GuzzleMessage(new Request('GET', 'https://127.0.0.1:' . $port . '/')))];

        $worker = new Guzzle($adapter);
        $worker->setLogger(new NullLogger());
        $worker->setTriggerErrorOnError(false);
        $worker->setRestartThreshold(1);

        $worker->run();
    } finally {
        pcntl_waitpid($pid, $status);
        fclose($server);
    }

    $this->assertContains(['afterWorkFailed', 19], $adapter->calls);
    $this->assertNotContains(['afterWorkSuccess', 19], $adapter->calls);
}
```

Why RED today: same ack-as-success behavior.

Keep the following as-is (they are intentional current behavior, not part of this fix):
- `testLogsServerErrorRejection` (a 500 response is a *delivered* HTTP response — the fulfillment
  path — and stays a success ack).
- `testDiscardsExpiredMessageAsSuccess`, `testRejectsUnsupportedPayloadAsSuccess`,
  `testRejectsNonStringPayloadAsSuccess` (payload-level outcomes, unchanged).
- `testAckFailureTriggersOuterCatch`, `testSendsAsyncRequestToLocalServer` (200 path).

**Phase B — fix** (`src/Worker/Guzzle.php`): capture `$processed` by reference in the rejection
handler and type it `Throwable` (covers `ConnectException`, `RequestException`, and any other
rejection):

```php
$message   = @unserialize($payload);
$processed = true;
...
try {
    $me = $this;

    $request = $message->getRequest();
    $promise = $client->sendAsync($request)->then(
        static function (ResponseInterface $fulfilledResponse) use ($me): void {
            $me->logDebug('Request sent, got response ' . $fulfilledResponse->getStatusCode() .
                     ' ' . json_encode(
                         (string) $fulfilledResponse->getBody(),
                         JSON_THROW_ON_ERROR
                     ));
        },
        static function (Throwable $rejectedResponse) use ($me, &$processed): void {
            $me->logDebug('Request sent, FAILED with ' . $rejectedResponse->getMessage());
            $processed = false;
        }
    );

    $promise->wait();
} catch (Throwable $e) {
    error_log('Error while sending FCM: ' . $e->getMessage());
} finally {
    $work->send((true === $processed));
}
```

`use Throwable;` is already imported (line 16). After this, `send(false)` in the `finally` drives
`AbstractWorker::work()` → `afterWorkFailed` → the job is re-queued instead of deleted.

Ripple effects: none to the fulfillment path; `testSendsAsyncRequestToLocalServer` stays green
(fulfillment leaves `$processed === true`).

---

## 2.2 HIGH — Nsq::connect() leaves a half-open connection after handshake failure

**Location:** `src/Adapter/Nsq.php`, `connect()` (lines ~425–451) and `writeIdentify()` (lines
~456–503).

**Mechanism:** `connect()` sets `$this->connected = true;` immediately after the socket opens, then
performs the magic bytes + IDENTIFY handshake inside `try`. Any handshake failure (error frame, null
feature list, missing auth, truncated frame, bad auth reply) is swallowed by `catch (Throwable $ex)`
and `connect()` returns `$this->connected` → **true**, leaving `_io` set and `connected = true` on a
socket the peer has already closed. Every subsequent operation on this "connected" adapter then fails
or hangs instead of reporting the connection as failed.

**Phase A — failing tests** (`tests/Adapter/NsqAdapterCoreTest.php`): the six tests currently assert
the buggy `assertTrue($nsq->connect())` for handshake-failure modes. Flip each to assert failure +
full rollback:

- `testConnectThrowsWhenIdentifyReplyIsNotAResponse` → `testConnectReturnsFalseOnIdentifyError`
  (mode `identify-error`)
- `testConnectThrowsWhenFeatureListIsNotAnArray` → `testConnectReturnsFalseOnUnknownFeatureList`
  (mode `identify-null`)
- `testConnectThrowsWhenAuthRequiredButMissing` → `testConnectReturnsFalseOnMissingAuth`
  (mode `identify-auth`)
- `testConnectThrowsWhenAuthReplyIsNotAResponse` → `testConnectReturnsFalseOnAuthError`
  (mode `auth-error`)
- `testConnectThrowsWhenAuthReplyIsNotJson` → `testConnectReturnsFalseOnAuthBadJson`
  (mode `auth-badjson`)
- `testConnectThrowsWhenFrameIsTruncated` → `testConnectReturnsFalseOnTruncatedFrame`
  (mode `short-frame`)

Body for each (mode-specific port from `startFakeServer`):

```php
[$process, $pipes, $port] = $this->startFakeServer('identify-error');
$nsq = new Nsq(self::TEST_HOST, $port);
$nsq->setTriggerErrorOnError(false);

try {
    $this->assertFalse($nsq->connect());
    $this->assertFalse((new ReflectionProperty(Nsq::class, 'connected'))->getValue($nsq));
    $this->assertNull((new ReflectionProperty(Nsq::class, '_io'))->getValue($nsq));
} finally {
    $this->stopFakeServer($process, $pipes);
}
```

Why RED today: `connect()` returns `true`, `connected === true`, `_io` is a set `StreamIO` → all three
assertions fail.

**Phase B — fix** (`src/Adapter/Nsq.php`, `connect()`): roll the connection state back on any handshake
failure:

```php
} catch (Throwable $ex) {
    $this->_io       = null;
    $this->connected = false;
    $this->logError($ex->getCode() . ': ' . $ex->getMessage());
}

return $this->connected;
```

Keep green: `testConnectReturnsFalseWhenPeerIsDown` (no socket → same), `testConnectTwiceRebindsConnection`
(healthy `multi` mode), `testConnectAuthenticatesWhenAuthProvided` (healthy `auth-ok`),
`testIdentifyHeartbeatIsRepliedWithNoop` (healthy). After the fix a failed `connect()` no longer leaves
a ghost socket, so `AbstractPublisher::start()`/`AbstractWorker::start()` correctly report `false`.

---

## 2.3 MEDIUM — teardown: noisy `StreamIO::close()` + Nsq disconnect blocking on dead sockets

**Location:** `src/Adapter/IO/StreamIO.php` `close()` (lines ~327–335) and `src/Adapter/Nsq.php`
`disconnect()` (lines ~143–172).

### 2.3-a `close()` may emit warnings on a dead socket

`close()` calls `stream_socket_shutdown($resource, STREAM_SHUT_RDWR)` then `fclose($resource)`
un-suppressed. On a socket whose peer already closed (broken pipe / SSL error) these can emit
warnings/notices into the worker log.

**Phase A — failing test** (`tests/Adapter/IO/StreamIOTest.php`), capture every warning raised:

```php
public function testCloseIsQuietOnDeadSocket(): void
{
    fclose($this->accepted);
    $this->accepted = null;

    // force the client socket into a consumed/EOF state first
    try {
        $this->io->read(4);
    } catch (\BackQ\Adapter\IO\Exception\TimeoutException) {
        // expected: peer closed
    }

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $this->io->close();
    } finally {
        restore_error_handler();
    }

    $this->assertSame([], $warnings);
}
```

**Caveat for the implementing agent:** whether this is RED depends on the container's PHP 8.3 /
platform behavior — it may be green even before the fix. Verify in the container; if it stays green,
keep the test as a regression guard and land the fix anyway.

**Phase B — fix** (`StreamIO::close()`): suppress expected shutdown noise:

```php
public function close(): void
{
    if (is_resource($this->sock)) {
        $resource = $this->sock;
        @stream_socket_shutdown($resource, STREAM_SHUT_RDWR);
        @fclose($resource);
    }
    $this->sock = null;
}
```

`testCloseIsRepeatedSafe` stays green (second call: `is_resource` false).

### 2.3-b `Nsq::disconnect()` blocks up to ~10 s waiting for CLOSE_WAIT on a dead socket

When in `BindRead` state, `disconnect()` writes `CLS` and then `readSuccessResponse(RESPONSE_CLOSED)`,
which blocks in a socket read with the configured 10 s stream timeout. On a peer that went away
without answering, teardown stalls for the full timeout (and worse, this is inside the worker's
`finish()` path, delaying restart/supervisor cycles).

**Phase A — failing test** (`tests/Adapter/NsqAdapterCoreTest.php`) — mock-based, fast and
deterministic (no 10 s wait needed to prove the code path):

```php
public function testDisconnectSkipsGracefulCloseWhenSocketDead(): void
{
    $io = $this->createMock(BackQ\Adapter\IO\StreamIO::class);
    // isSocketReady() returns TRUE when the socket is BROKEN (EOF / timed out)
    $io->method('isSocketReady')->willReturn(true);
    $io->expects($this->never())->method('write');
    $io->expects($this->once())->method('close');

    $nsq = new Nsq(self::TEST_HOST, self::TEST_PORT);
    $this->setState($nsq, true, ConnectionState::BindRead);
    (new ReflectionProperty(Nsq::class, '_io'))->setValue($nsq, $io);

    $this->assertTrue($nsq->disconnect());
}
```

Why RED today: `disconnect()` with `BindRead` unconditionally writes `CLS` → `$io->write(...)` is
called → the `never()` expectation fails.

**Phase B — fix** (`src/Adapter/Nsq.php`, `disconnect()`): only attempt the graceful `CLS`/`CLOSE_WAIT`
exchange on a healthy socket:

```php
if (ConnectionState::BindRead === $this->state) {
    $io = $this->_io;
    \assert($io instanceof IO\StreamIO);
    if (true === $io->isSocketReady()) {
        $this->logDebug(__FUNCTION__ . ' socket is not healthy, skipping graceful close');
    } else {
        $this->writeCommand(self::PROTO_CLOSE);
        $this->readSuccessResponse(self::RESPONSE_CLOSED);
    }
}
$io = $this->_io;
\assert($io instanceof IO\StreamIO);
$io->close();
```

Healthy teardown (e.g. `testAfterWorkFailedRequeuesMessage` / `testProtocolRoundTrip` equivalents)
still performs the graceful exchange because `isSocketReady()` is false on a live socket. Keep the
existing `catch (Throwable)` outer guard.

---

## Register: existing tests that assert buggy behavior (flip in Phase A / land with Phase B)

| Test file | Test | Current assertion | After fix |
|---|---|---|---|
| `tests/Worker/GuzzleWorkerTest.php` | `testLogsConnectRefusedFailure` | `assertContains(['afterWorkSuccess', 16], ...)` | `afterWorkFailed` + no success ack |
| `tests/Adapter/NsqAdapterCoreTest.php` | 6 handshake-failure connect tests (identify-error, identify-null, identify-auth, auth-error, auth-badjson, short-frame) | `assertTrue($nsq->connect())` | `assertFalse` + `connected=false` + `_io=null` |

## Verification (run inside the `backq.php83` container per AGENTS.md)

```bash
$script = @'
cd /app
php -l src/Worker/Guzzle.php
php -l src/Adapter/Nsq.php
php -l src/Adapter/IO/StreamIO.php
php -l tests/Worker/GuzzleWorkerTest.php
php -l tests/Adapter/NsqAdapterCoreTest.php
php -l tests/Adapter/IO/StreamIOTest.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src/Adapter/IO/StreamIO.php tests/Worker/GuzzleWorkerTest.php tests/Adapter/NsqAdapterCoreTest.php tests/Adapter/IO/StreamIOTest.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Worker/Guzzle.php src/Adapter/Nsq.php src/Adapter/IO/StreamIO.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter 'GuzzleWorker|NsqAdapter|StreamIO'
php ./vendor/bin/phpunit --configuration=phpunit.xml
'@
$script | docker exec -i backq.php83 bash -s
```

Notes:
- `Guzzle.php` (method-level `@phpcs:disable`), `Nsq.php` and `StreamIO.php` (class-level) are skipped
  by phpcs — rely on `php -l` + PHPStan + tests.
- PHPUnit must run inside the container only (per AGENTS.md).

## Risks for the implementing agent

- 2.1 changes observable worker behavior: transport failures now re-queue the job (visible in queue
  metrics / logs). Document in `UPGRADING` if the repo requires an entry for behavior changes.
- 2.2 changes `Nsq::connect()` to return `false` instead of `true` for handshake failures — verify no
  caller relies on the old half-open state. `NsqAdapterTest::testPublisherToConsumerFlow` (real nsqd)
  is unaffected.
- 2.3-b uses `isSocketReady()` whose semantics are inverted-by-name (returns `true` when broken);
  keep the comment in the fix so the next reader is not misled.