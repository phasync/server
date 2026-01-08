<?php

require __DIR__ . '/../vendor/autoload.php';

use phasync\Net\TcpServer;

$port = $argv[1] ?? 8080;

phasync::run(function () use ($port) {
    // No AsyncStream wrapping, zero-copy buffers
    $server = new TcpServer("0.0.0.0:$port", wrapStreams: false, readBuffer: 0, writeBuffer: 0);
    echo "Listening on http://0.0.0.0:$port (no wrap, zero-copy)\n";

    $response = "HTTP/1.0 200 OK\r\nContent-Length: 12\r\nConnection: close\r\n\r\nHello World\n";

    foreach ($server->accept() as $stream) {
        phasync::go(function () use ($stream, $response) {
            fread(phasync::readable($stream), 65536);
            fwrite(phasync::writable($stream), $response);
            fclose($stream);
        });
    }
});
