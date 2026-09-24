<?php

// Minimal keep-alive HTTP/1.1 responder on TcpServer, without an HTTP library: read until a
// blank line, write a canned response, repeat until the client closes the connection.
// Usage: php tcpserver.php [port]

require __DIR__ . '/../vendor/autoload.php';

use phasync\Net\TcpServer;

$port = (int) ($argv[1] ?? 8080);
$body = "Hello World\n";
$resp = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . \strlen($body)
    . "\r\nConnection: keep-alive\r\n\r\n" . $body;

\phasync\try_enable_ext();

phasync::run(function () use ($port, $resp) {
    $server = new TcpServer("0.0.0.0:$port");
    echo 'TcpServer listening on ', $server->getAddress(), extension_loaded('phasync') ? ' (phasync extension loaded)' : '', "\n";

    foreach ($server->accept() as $conn) {
        phasync::go(function () use ($conn, $resp) {
            $buf = '';
            while (true) {
                $chunk = \fread(phasync::readable($conn, \PHP_FLOAT_MAX), 65536);
                if ('' === $chunk || false === $chunk) {
                    if (\feof($conn)) {
                        break;
                    }
                    continue;
                }
                $buf .= $chunk;
                $n = 0;
                while (false !== ($p = \strpos($buf, "\r\n\r\n"))) {
                    $buf = \substr($buf, $p + 4);
                    ++$n;
                }
                if ($n > 0) {
                    \fwrite(phasync::writable($conn, \PHP_FLOAT_MAX), 1 === $n ? $resp : \str_repeat($resp, $n));
                }
            }
            \fclose($conn);
        });
    }
});
