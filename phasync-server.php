<?php

use phasync\Server\Server;

require(__DIR__ . '/vendor/autoload.php');

if ($pid) {
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    die("Done\n");
}

Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
    phasync::readable($stream);     // Wait for data from the client
    fread($stream, 65536);
    phasync::writable($stream);     // Wait until the client is ready to receive data
    fwrite($stream, 
        "HTTP/1.1 200 Ok\r\n".
        "Connection: close\r\n".
        "Content-Length: 13\r\n".
        "\r\n".
        "Hello, world!"
    );
    fclose($stream);
});    
