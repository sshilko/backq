<?php

/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2013-2019 Sergei Shilko
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Minimal scripted nsqd stand-in used by the offline Nsq adapter tests.
 *
 * Binds an ephemeral TCP port, prints "PORT=<port>" to stdout, then plays a
 * fixed protocol dance. All modes run the magic + IDENTIFY handshake and
 * answer with a feature list frame; afterwards each mode differs:
 *   heartbeat          - answer RDY with a heartbeat frame and expect a NOP
 *   message            - answer RDY with a single message frame
 *   idle               - send the feature list and hold the connection open
 *   requeue            - answer SUB with OK and read a REQ command
 *   non-message        - answer RDY with a plain response frame
 *   pubdelay           - read a DPUB command with body and answer OK
 *   identify-error     - answer IDENTIFY with an error frame
 *   identify-null      - answer IDENTIFY with a literal null feature list
 *   identify-auth      - advertise auth_required in the feature list
 *   auth-ok            - read AUTH and answer with a JSON identity
 *   auth-error         - answer AUTH with an error frame
 *   auth-badjson       - answer AUTH with an unparseable payload
 *   sub-bad            - answer SUB with an error frame
 *   identify-heartbeat - answer IDENTIFY with a heartbeat, then features
 *   short-frame        - answer IDENTIFY with a truncated frame
 *   multi              - accept two consecutive connections
 *
 * Usage: php FakeNsqdServer.php <mode>
 */

$mode = $argv[1] ?? 'heartbeat';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, 'Unable to bind test server: ' . $errstr . PHP_EOL);
    exit(1);
}

$parts = explode(':', stream_socket_get_name($server, false));
fwrite(STDOUT, 'PORT=' . end($parts) . PHP_EOL);
fflush(STDOUT);

$readN = static function ($sock, int $n): string {
    $data     = '';
    $deadline = microtime(true) + 10;

    while (strlen($data) < $n && microtime(true) < $deadline) {
        $chunk = fread($sock, $n - strlen($data));
        if (false === $chunk) {
            break;
        }
        $data .= $chunk;
        if ('' === $chunk) {
            usleep(1000);
        }
    }

    return $data;
};

$readCmd = static function ($sock): string {
    $data     = '';
    $deadline = microtime(true) + 10;

    while (false === strpos($data, "\n") && microtime(true) < $deadline) {
        $data .= fread($sock, 1);
    }

    return $data;
};

$frame = static function (int $type, string $body): string {
    return pack('N', 4 + strlen($body)) . pack('N', $type) . $body;
};

$exit = static function (int $code) use ($server): void {
    fclose($server);
    exit($code);
};

$features = static function (bool $authRequired, string $version = '1.0.0'): string {
    return json_encode([
        'auth_required' => $authRequired,
        'max_rdy_count' => 2500,
        'version'       => $version,
    ]);
};

$accept = static function () use ($server, $exit) {
    $conn = stream_socket_accept($server, 10);
    if (false === $conn) {
        $exit(2);
    }

    return $conn;
};

$handshake = static function ($conn) use ($readN, $readCmd): bool {
    if ('  V2' !== $readN($conn, 4)) {
        return false;
    }
    $cmd = trim($readCmd($conn));
    $len = unpack('N', $readN($conn, 4))[1];
    $readN($conn, $len);

    return 'IDENTIFY' === $cmd;
};

$messageBody = pack('J', 1620000000 * 1000000000) . pack('n', 1) . 'msgid01234567890' . 'the-payload';

switch ($mode) {
    case 'message':
    case 'heartbeat':
    default:
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);

        $cmd = trim($readCmd($conn));
        if ('SUB' !== substr($cmd, 0, 3)) {
            fclose($conn);
            $exit(5);
        }
        fwrite($conn, $frame(0, 'OK'));
        fflush($conn);

        $cmd = trim($readCmd($conn));
        if ('RDY' !== substr($cmd, 0, 3)) {
            fclose($conn);
            $exit(6);
        }

        if ('message' === $mode) {
            fwrite($conn, $frame(2, $messageBody));
            fflush($conn);
        } else {
            fwrite($conn, $frame(0, '_heartbeat_'));
            fflush($conn);
            $cmd = trim($readCmd($conn));
            if ('NOP' !== $cmd) {
                fclose($conn);
                $exit(7);
            }
        }
        fclose($conn);
        $exit(0);

        break;
    case 'idle':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);
        usleep(3000000);
        fclose($conn);
        $exit(0);

        break;
    case 'requeue':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(4);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);

        if ('SUB' !== substr(trim($readCmd($conn)), 0, 3)) {
            fclose($conn);
            $exit(5);
        }
        fwrite($conn, $frame(0, 'OK'));
        fflush($conn);

        if ('REQ' !== substr(trim($readCmd($conn)), 0, 3)) {
            fclose($conn);
            $exit(6);
        }
        fclose($conn);
        $exit(0);

        break;
    case 'non-message':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);

        if ('SUB' !== substr(trim($readCmd($conn)), 0, 3)) {
            fclose($conn);
            $exit(5);
        }
        fwrite($conn, $frame(0, 'OK'));
        fflush($conn);

        if ('RDY' !== substr(trim($readCmd($conn)), 0, 3)) {
            fclose($conn);
            $exit(6);
        }
        fwrite($conn, $frame(0, 'OK'));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'pubdelay':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);

        if ('DPUB' !== substr(trim($readCmd($conn)), 0, 4)) {
            fclose($conn);
            $exit(6);
        }
        $len = unpack('N', $readN($conn, 4))[1];
        $readN($conn, $len);

        fwrite($conn, $frame(0, 'OK'));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'identify-error':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(1, 'ERR'));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'identify-null':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, 'null'));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'identify-auth':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(true)));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'auth-ok':
    case 'auth-error':
    case 'auth-badjson':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(true)));
        fflush($conn);

        if ('AUTH' !== trim($readCmd($conn))) {
            fclose($conn);
            $exit(6);
        }
        $len = unpack('N', $readN($conn, 4))[1];
        $readN($conn, $len);

        if ('auth-ok' === $mode) {
            fwrite($conn, $frame(0, json_encode(['identity' => 'tester'])));
        } elseif ('auth-error' === $mode) {
            fwrite($conn, $frame(1, 'Invalid credentials'));
        } else {
            fwrite($conn, $frame(0, 'nope'));
        }
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'sub-bad':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);

        if ('SUB' !== substr(trim($readCmd($conn)), 0, 3)) {
            fclose($conn);
            $exit(5);
        }
        fwrite($conn, $frame(1, 'ERROR'));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'identify-heartbeat':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, $frame(0, '_heartbeat_'));
        fflush($conn);
        fwrite($conn, $frame(0, $features(false)));
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'short-frame':
        $conn = $accept();
        if (!$handshake($conn)) {
            fclose($conn);
            $exit(3);
        }
        fwrite($conn, pack('N', 100) . pack('N', 0) . 'abc');
        fflush($conn);
        fclose($conn);
        $exit(0);

        break;
    case 'multi':
        for ($i = 0; $i < 2; $i++) {
            $conn = $accept();
            if (!$handshake($conn)) {
                fclose($conn);
                $exit(8);
            }
            fwrite($conn, $frame(0, $features(false)));
            fflush($conn);
            usleep(2000000);
            fclose($conn);
        }
        $exit(0);
}
