<?php

use phasync\Net\TcpServer;

test('TcpServer supports Unix Domain Sockets', function () {
    // Skip on Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $this->markTestSkipped('Unix sockets not supported on Windows');
    }

    $socketPath = sys_get_temp_dir() . '/phasync_test_' . uniqid() . '.sock';
    if (file_exists($socketPath)) unlink($socketPath);

    phasync::run(function () use ($socketPath) {
        $server = new TcpServer("unix://$socketPath");
        $response = null;

        // Server coroutine
        phasync::go(function() use ($server) {
            foreach ($server->accept() as $conn) {
                fwrite(phasync::writable($conn), "Hello Unix");
                fclose($conn);
                $server->close();
                break;
            }
        });

        // Client - must also use non-blocking operations
        $client = stream_socket_client("unix://$socketPath", $errno, $errstr);
        expect($client)->toBeResource();
        stream_set_blocking($client, false);

        phasync::readable($client);
        $response = fread($client, 65536);
        fclose($client);

        expect($response)->toBe("Hello Unix");
    });

    if (file_exists($socketPath)) unlink($socketPath);
});
