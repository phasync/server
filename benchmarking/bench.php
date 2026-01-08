<?php

require __DIR__ . '/../vendor/autoload.php';

use phasync\Net\TcpServer;

$port = $argv[1] ?? 8080;
$cores = (int) trim(`nproc` ?: `cat /proc/cpuinfo|grep processor|wc -l`) ?: 4;

phasync::run(function () use ($port, $cores) {
    $server = new TcpServer("0.0.0.0:$port");
    echo "Listening on http://0.0.0.0:$port (with AsyncStream wrapping, $cores workers)\n";

    // Fork workers
    for ($i = 1; $i < $cores; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            break; // Child continues to accept loop
        }
    }

    $response = "HTTP/1.0 200 OK\r\nContent-Length: 12\r\nConnection: close\r\n\r\nHello World\n";

    foreach ($server->accept() as $stream) {
        phasync::go(function () use ($stream, $response) {
            fread($stream, 65536);
            fwrite($stream, $response);
            fclose($stream);
        });
    }
});
