# Plan 1 — Protocol / TCP frame handling (findings 1.1 – 1.4)

> Status: **documented only** — no code changes made. Intended for implementation by another agent.
> Scope: section 1 of the analysis report (protocol / TCP frame errors).
> Companion plans: `plan-2-network-ssl-disconnects.md`, `plan-3-connection-liveness-resilience.md`,
> `plan-4-minor-notes-behavior.md`.

## How to execute this plan

Two distinct steps, in order, per item:

1. **Phase A — tests**: apply the failing tests exactly as written below, run them, and confirm they
   are **RED** on the current code. Do not touch `src/` in this phase.
2. **Phase B — implementation**: apply the corresponding fix, then confirm the same tests go
   **GREEN** and the full suite stays green.

Also update the registers at the bottom of each item: existing tests that currently *assert the buggy
behavior* and must be flipped in **Phase B** (after the fix they would fail, so they are updated in the
same change that lands the fix — never before it).

---

## 1.1 HIGH — Beanstalk `_read($length)` rtrim() corrupts job bodies ending in CR/LF

**Location:** `src/Adapter/Beanstalk/Client.php`, `_read()` (lines ~245–298), the `if ($length)` branch
(payload read). The corrupting line is:

```php
if ('' !== $packet) {
    $packet = rtrim($packet, "\r\n");
}
```

**Mechanism:** the responder writes `bodyN` bytes followed by the 2-byte protocol terminator `\r\n`.
`stream_get_contents($length + 2)` returns `body . "\r\n"`. `rtrim($packet, "\r\n")` strips **every**
trailing CR/LF character, so a job whose payload legitimately ends with `\r\n` (or `\n`, `\r`) is
silently truncated. The job is then queued/processed/delivered with a corrupted payload.

**Phase A — failing test** (`tests/Adapter/Beanstalk/ClientTest.php`):

```php
public function testReservePreservesBodyTrailingCrlf(): void
{
    $client = $this->connectClient();

    // body of 7 bytes = "hello\r\n", followed by the protocol terminator "\r\n"
    $this->server->queueResponse("RESERVED 7 7\r\nhello\r\n\r\n");

    $reserved = $client->reserve(5);
    $this->assertIsArray($reserved);
    $this->assertSame(7, $reserved['id']);
    $this->assertSame("hello\r\n", $reserved['body']);

    $client->disconnect();
}
```

Also add a lone-`\n` variant (body `"x\n"` → wire `"RESERVED 1 2\r\nx\n\r\n"`, expect `"x\n"`).

Why it is RED today: `_read(7)` returns `rtrim("hello\r\n\r\n", "\r\n")` = `"hello"`, so
`assertSame("hello\r\n", $reserved['body'])` fails.

**Phase B — fix** (`src/Adapter/Beanstalk/Client.php`, `_read()`): strip only the exact 2-byte
terminator when present, never arbitrary trailing bytes:

```php
if ($length) {
    try {
        $packet = $io->stream_get_contents($length + 2);
        if (false === $packet) {
            throw new RuntimeException('Failed to io.stream_get_contents on ' . __FUNCTION__);
        }
        if (strlen($packet) < $length + 2) {
            throw new RuntimeException(sprintf(
                'Failed to read complete job body, expected %d bytes, got %d',
                $length + 2,
                strlen($packet)
            ));
        }
        if (2 <= strlen($packet) && "\r\n" === substr($packet, -2)) {
            $packet = substr($packet, 0, -2);
        }
    } catch (IO\Exception\TimeoutException $ex) {
        // unchanged: READ_EOF_CODE => return false; otherwise rethrow
    }
}
```

Ripple effects to verify (existing tests keep passing):
- `testReserveRoundTrip`, `testReserveWithoutTimeoutRoundTrip`, `testProtocolRoundTrip`: bodies without
  trailing CR/LF → unchanged.
- `testStatsRoundTrip`, `testStatsTubeRoundTrip`: `queueStats()` writes a YAML body without a trailing
  newline, so `substr($packet, -2)` is not `\r\n`... verify: wire is `yaml . "\r\n"`, length-branch reads
  `strlen($yaml) + 2` bytes = `yaml . "\r\n"` → terminator present → stripped once → `yaml` unchanged.

Note: `Client.php` carries a class-level `@phpcs:disable` (AGENTS.md quirk) — phpcs/phpcbf skip it, so
verify with `php -l` + PHPStan + tests instead.

---

## 1.2 HIGH — Beanstalk truncated job body accepted as a complete job

**Location:** same `_read()` length branch as 1.1.

**Mechanism:** when the peer disconnects mid-body, `stream_get_contents($length + 2)` returns the
partial bytes already received (not `false`). The current code returns them as the job body, so the
worker reserves, processes, and **deletes** a truncated job → payload loss, no error.

**Phase A — failing test** (`tests/Adapter/Beanstalk/ClientTest.php`):

```php
public function testReserveThrowsOnTruncatedBody(): void
{
    $client = $this->connectClient();

    // status says 100-byte body but only "partial" arrives before the peer closes
    $this->server->queueResponse("RESERVED 7 100\r\npartial");
    $this->server->close();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Failed to read complete job body');

    $client->reserve(5);
}
```

Why it is RED today: `_read(100)` returns `"partial"` (after the old rtrim), `reserve()` returns
`['id' => 7, 'body' => 'partial']` and no exception is raised → `expectException` is never satisfied.

**Phase B — fix:** the length-validation block is included in the 1.1 fix above (the
`strlen($packet) < $length + 2` throw). It must land together with the 1.1 change.

**Dependency:** after this fix `reserve()` throws on a broken connection instead of returning a fake
job. `Beanstalk::pickTask()` currently swallows `Throwable` and returns `false` → a busy-loop. That is
fixed in **Plan 3 item 3.3** (`Beanstalk::pickTask` rethrows). Implement this item together with 3.3,
or accept the temporary busy-loop.

---

## 1.3 MEDIUM — StreamIO::read() framing: silent short read on EOF, over-read on retries

**Location:** `src/Adapter/IO/StreamIO.php`, `read(int $n)` (lines ~179–226), and `close()` must stay
unchanged here (see Plan 2, item 2.3).

**Mechanism:**
- The retry loop calls `fread($sock, $n)` on every iteration instead of `$n - strlen($fread_result)`.
  When the first `fread` returns partial data and more bytes arrive before the loop exits, `read()`
  can return **more** than `$n` bytes and eat into the next protocol field.
- When the peer closes mid-read with partial data buffered, the loop exits silently and returns the
  partial string. Callers that do check the length (Nsq::read) then classify a clean disconnection as
  a protocol error (`Failed to read N bytes from IO`) instead of a clean EOF.

Note: `Nsq::read()` already length-checks its `read()` result, so the *current observable harm* is
misclassification + desync risk; keep the fix defensive.

**Phase A — failing tests** (`tests/Adapter/IO/StreamIOTest.php`; `setUp()` creates a **non-blocking**
`StreamIO` because `$blocking` defaults to `false`):

```php
public function testReadThrowsWhenPeerClosesMidRead(): void
{
    // only 2 of the requested 4 bytes arrive, then the peer closes
    fwrite($this->accepted, '12');
    fclose($this->accepted);
    $this->accepted = null;

    $this->expectException(TimeoutException::class);
    $this->expectExceptionMessage('Socket connection EOF');

    $this->io->read(4);
}
```

Why it is RED today: the loop consumes `'12'`, then hits EOF, and returns `'12'` with no exception →
`expectException` is never satisfied.

Regression guard (should be GREEN both before and after — do not delete):

```php
public function testReadReturnsExactlyRequestedLengthAtFrameBoundary(): void
{
    fwrite($this->accepted, '12345678');

    $this->assertSame('1234', $this->io->read(4));
    $this->assertSame('5678', $this->io->read(4));
}
```

**Phase B — fix** (`src/Adapter/IO/StreamIO.php`, `read()`): read only the remaining length and make a
short read on a dead/timeout stream throw instead of returning silently:

```php
public function read(int $n): string
{
    $sock = $this->sock;
    if (null === $sock) {
        throw new RuntimeException('No active socket connection');
    }

    $info = stream_get_meta_data($sock);

    if ($info['eof'] || @feof($sock)) {
        throw new TimeoutException('Error reading data. Socket connection EOF', self::READ_EOF_CODE);
    }

    if ($info['timed_out']) {
        throw new TimeoutException('Error reading data. Socket connection TIME OUT', self::READ_TIME_CODE);
    }

    $tries = self::FREAD_0_TRIES;
    $fread_result = '';
    while (!@feof($sock) && strlen($fread_result) < $n) {
        $remaining = $n - strlen($fread_result);
        $fdata     = @fread($sock, $remaining);
        if (false === $fdata) {
            throw new RuntimeException('Failed to fread() from socket', self::READ_ERR_CODE);
        }
        $fread_result .= $fdata;

        if ('' === $fdata) {
            $tries--;
        }

        if ($tries <= 0) {
            break;
        }
    }

    if (strlen($fread_result) < $n) {
        $info = stream_get_meta_data($sock);
        if ($info['eof'] || @feof($sock)) {
            throw new TimeoutException('Error reading data. Socket connection EOF', self::READ_EOF_CODE);
        }
        if ($info['timed_out']) {
            throw new TimeoutException('Error reading data. Socket connection TIME OUT', self::READ_TIME_CODE);
        }
    }

    return $fread_result;
}
```

Check the existing suite stays green:
- `testRead` (full buffer) and `testReadAfterPeerCloseThrowsTimeout` (first `read(4)` gets exactly 4
  bytes → no post-loop throw; second read hits the entry EOF check → TimeoutException).
- `testTimedOutSocketThrowsOnReadsAndWrites`: the first `read(8)` still fails inside the loop with
  `Failed to fread() from socket` (unchanged path); later calls hit the entry `timed_out` check.

---

## 1.4 MEDIUM — NSQ `readFrame()` accepts unbounded / negative frame sizes

**Location:** `src/Adapter/Nsq.php`, `readFrame()` (lines ~607–634) → `$this->read($frameSize - 4)`,
plus `read()` (lines ~651–663).

**Mechanism:** a corrupt/attack frame declaring `frameSize < 4` produces a negative read (wrong error
class, then desync), and `frameSize` huge (e.g. `0xFFFFFFF0`) makes `read()` block until the stream
timeout (potentially ~10–30 s) before failing. The server's own `--max-msg-size` limit is never
mirrored client-side, so the client should validate the value up-front and fail fast.

**Phase A — failing tests** (`tests/Adapter/NsqAdapterCoreTest.php`). Add two new modes to
`tests/Support/FakeNsqdServer.php` (~line 118 switch):

```php
case 'bad-framesize':
    $conn = $accept();
    if (!$handshake($conn)) {
        fclose($conn);
        $exit(3);
    }
    // frame size 0xFFFFFFF0 (4-byte body field), frame type 0, 4 bytes of junk, then close
    fwrite($conn, pack('N', 0xFFFFFFF0) . pack('N', 0) . 'junk');
    fflush($conn);
    fclose($conn);
    $exit(0);

    break;
case 'bad-framesize-tiny':
    $conn = $accept();
    if (!$handshake($conn)) {
        fclose($conn);
        $exit(3);
    }
    // frame size < 4 is invalid per the NSQ spec
    fwrite($conn, pack('N', 3) . pack('N', 0));
    fflush($conn);
    fclose($conn);
    $exit(0);

    break;
```

Then drive `readFrame()` directly with a real `StreamIO` so the test is fast and deterministic (the
adapter's `connect()` swallows exceptions, see Plan 2 item 2.2):

```php
public function testReadFrameRejectsHugeFrameSize(): void
{
    [$process, $pipes, $port] = $this->startFakeServer('bad-framesize');
    try {
        $nsq = new Nsq(self::TEST_HOST, $port);
        $this->setState($nsq, true, ConnectionState::Nothing);

        $io = new BackQ\Adapter\IO\StreamIO(self::TEST_HOST, $port, 1, 2, null, true);
        (new ReflectionProperty(Nsq::class, '_io'))->setValue($nsq, $io);

        $method = new ReflectionMethod(Nsq::class, 'readFrame');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid frame size');

        $method->invoke($nsq, true);
    } finally {
        $this->stopFakeServer($process, $pipes);
    }
}

public function testReadFrameRejectsTinyFrameSize(): void
{
    [$process, $pipes, $port] = $this->startFakeServer('bad-framesize-tiny');
    try {
        $nsq = new Nsq(self::TEST_HOST, $port);
        $this->setState($nsq, true, ConnectionState::Nothing);

        $io = new BackQ\Adapter\IO\StreamIO(self::TEST_HOST, $port, 1, 2, null, true);
        (new ReflectionProperty(Nsq::class, '_io'))->setValue($nsq, $io);

        $method = new ReflectionMethod(Nsq::class, 'readFrame');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid frame size');

        $method->invoke($nsq, true);
    } finally {
        $this->stopFakeServer($process, $pipes);
    }
}
```

Why they are RED today: no validation exists — `readFrame()` reports
`Failed to read 4294967276 bytes from IO` (huge) or `Failed to read -1 bytes` (tiny), never
`Invalid frame size`; the message assertion fails.

**Phase B — fix** (`src/Adapter/Nsq.php`): add a constant + validate in `readFrame()` right after
`$frameSize` is read:

```php
protected final const int MAX_FRAME_SIZE = 16777216; // 16 MiB, generous vs nsqd default --max-msg-size (1 MiB)
```

```php
while (true) {
    $frameSize = $this->readInt();
    if ($frameSize < 4 || $frameSize > self::MAX_FRAME_SIZE) {
        throw new RuntimeException(sprintf('Invalid frame size %d', $frameSize));
    }
    $frameType = $this->readInt();
    ...
}
```

Ripple effects: `short-frame` mode (truncated identify frame, used by the flipped connect test in
Plan 2 item 2.2) still fails on the read itself → generic failure → `connect()` returns false,
unchanged.

---

## Register: existing tests that assert buggy behavior (flip in Phase B only)

| Test | Current assertion | After fix |
|---|---|---|
| (none in this plan assert the bug; 1.2 interacts with Plan 3 item 3.3) | — | — |

## Verification (run inside the `backq.php83` container per AGENTS.md)

```bash
# Windows PowerShell: single-quoted here-string so $vars reach bash intact
$script = @'
cd /app
php -l src/Adapter/Beanstalk/Client.php
php -l src/Adapter/IO/StreamIO.php
php -l src/Adapter/Nsq.php
php -l tests/Adapter/Beanstalk/ClientTest.php
php -l tests/Adapter/IO/StreamIOTest.php
php -l tests/Adapter/NsqAdapterCoreTest.php
php -l tests/Support/FakeNsqdServer.php
php -d memory_limit=-1 vendor/bin/phpcs --standard=build/phpcs-ruleset.xml --no-cache -s src/Adapter/IO/StreamIO.php src/Adapter/Nsq.php tests/Adapter/Beanstalk/ClientTest.php tests/Adapter/IO/StreamIOTest.php tests/Adapter/NsqAdapterCoreTest.php tests/Support/FakeNsqdServer.php --report=full
php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=-1 --no-progress -c build/phpstan.neon src/Adapter/Beanstalk/Client.php src/Adapter/IO/StreamIO.php src/Adapter/Nsq.php
php ./vendor/bin/phpunit --configuration=phpunit.xml --filter 'Beanstalk|StreamIO|NsqAdapter'
php ./vendor/bin/phpunit --configuration=phpunit.xml
'@
$script | docker exec -i backq.php83 bash -s
```

Notes:
- `Client.php` and `Nsq.php`/`StreamIO.php` carry `@phpcs:disable` — phpcs skips them; rely on
  `php -l` + PHPStan + tests.
- Run PHPUnit **only** inside the container (`failOnWarning` is off, but a load-time fatal kills the
  suite silently — watch for a run that stops at a dot with no summary, per AGENTS.md).

## Risks for the implementing agent

- 1.2 makes `Beanstalk\Client::reserve()` throw on broken connections; without the **Plan 3 item 3.3**
  change (`Beanstalk::pickTask` rethrows), the adapter busy-loops logging. Land them together.
- `StreamIO::read()` is consumed by `Nsq` only (all other adapters use `stream_get_contents` /
  `stream_get_line`); the post-loop throw changes exception classes for Nsq disconnect paths — verify
  `NsqAdapterCoreTest` after the change.