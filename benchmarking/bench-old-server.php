<?php

require __DIR__ . '/../vendor/autoload.php';

use phasync\Server\Server;

$port = $argv[1] ?? 8080;

phasync::run(function () use ($port) {
    echo "Listening on http://0.0.0.0:$port (old Server class)\n";

    $response = "HTTP/1.0 200 OK\r\nContent-Length: 12\r\nConnection: close\r\n\r\nHello World\n";

    Server::serve("tcp://0.0.0.0:$port", function ($stream, $peer) use ($response) {
        phasync::go(function () use ($stream, $response) {
            fread(phasync::readable($stream), 65536);
            fwrite(phasync::writable($stream), $response);
            fclose($stream);
        });
    });
});
