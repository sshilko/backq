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
 * fixed protocol dance:
 *   magic, IDENTIFY+body, feature frame, SUB+OK, then per $mode:
 *     heartbeat  - answer RDY with a heartbeat frame and expect a NOP
 *     message    - answer RDY with a single message frame
 *
 * Usage: php FakeNsqdServer.php <heartbeat|message>
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

$readN = static function ($sock, int $n) use (&$readN): string {
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

$readCmd = static function ($sock) use (&$readCmd): string {
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

$conn = stream_socket_accept($server, 10);
if (false === $conn) {
    $exit(2);
}

$magic = $readN($conn, 4);
if ('  V2' !== $magic) {
    $exit(3);
}

$cmd = trim($readCmd($conn));
$len = unpack('N', $readN($conn, 4))[1];
$readN($conn, $len);
if ('IDENTIFY' !== $cmd) {
    $exit(4);
}
fwrite($conn, $frame(0, json_encode([
    'auth_required' => false,
    'max_rdy_count' => 2500,
    'version'       => '1.0.0',
])));
fflush($conn);

$cmd = trim($readCmd($conn));
if ('SUB' !== substr($cmd, 0, 3)) {
    $exit(5);
}
fwrite($conn, $frame(0, 'OK'));
fflush($conn);

$cmd = trim($readCmd($conn));
if ('RDY' !== substr($cmd, 0, 3)) {
    $exit(6);
}

if ('message' === $mode) {
    $msgId   = 'msgid01234567890';
    $payload = 'the-payload';
    $body    = pack('J', 1620000000 * 1000000000) . pack('n', 1) . $msgId . $payload;
    fwrite($conn, $frame(2, $body));
    fflush($conn);
} else {
    fwrite($conn, $frame(0, '_heartbeat_'));
    fflush($conn);
    $cmd = trim($readCmd($conn));
    if ('NOP' !== $cmd) {
        $exit(7);
    }
}

fclose($conn);
$exit(0);
