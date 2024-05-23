<?php
require(__DIR__ . '/vendor/autoload.php');




$server = \stream_socket_server('tcp://0.0.0.0:23432', $c, $m, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, stream_context_create([
    'socket' => [
        'backlog' => 511,       // Allow PHP to hold a number of sockets while doing other stuff
        'so_reuseport' => true, // See README.md
        'tcp_nodelay' => false,  // See README.md
    ]
]
));

while (true) {
    $stream = \stream_socket_accept($server);
    $d = fread($stream, 65536);
    $w = fwrite($stream, 
        "HTTP/1.1 200 Ok\r\n".
        "Connection: close\r\n".
        "Content-Length: 13\r\n".
        "\r\n".
        "Hello, world!"
    );
    fclose($stream);
}    
