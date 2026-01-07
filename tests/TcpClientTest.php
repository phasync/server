<?php

use phasync\Net\TcpServer;
use phasync\Net\TcpClient;

function get_client_test_port(): int {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $addr, $port);
    socket_close($sock);
    return $port;
}

test('TcpClient connects and exchanges data', function () {
    phasync::run(function () {
        $port = get_client_test_port();
        $server = new TcpServer("127.0.0.1:$port");

        // Server coroutine
        phasync::go(function () use ($server) {
            foreach ($server->accept() as $stream) {
                $data = fread($stream, 65536);
                fwrite($stream, "Echo: $data");
                fclose($stream);
                $server->close();
                break;
            }
        });

        // Client using TcpClient - AsyncStream handles blocking
        $client = TcpClient::connect("127.0.0.1:$port");
        expect($client)->toBeResource();

        fwrite($client, "Hello");
        $response = fread($client, 65536);
        fclose($client);

        expect($response)->toBe("Echo: Hello");
    });
});

test('TcpClient throws on refused connection', function () {
    phasync::run(function () {
        // Port 1 requires root and is almost certainly not listening
        expect(fn() => TcpClient::connect('127.0.0.1:1', timeout: 0.5))
            ->toThrow(RuntimeException::class);
    });
});

test('TcpClient connects to Unix socket', function () {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $this->markTestSkipped('Unix sockets not supported on Windows');
    }

    $socketPath = sys_get_temp_dir() . '/phasync_client_test_' . uniqid() . '.sock';
    if (file_exists($socketPath)) unlink($socketPath);

    phasync::run(function () use ($socketPath) {
        $server = new TcpServer("unix://$socketPath");

        phasync::go(function () use ($server) {
            foreach ($server->accept() as $stream) {
                fwrite($stream, "Unix OK");
                fclose($stream);
                $server->close();
                break;
            }
        });

        $client = TcpClient::connectUnix($socketPath);
        $response = fread($client, 65536);
        fclose($client);

        expect($response)->toBe("Unix OK");
    });

    if (file_exists($socketPath)) unlink($socketPath);
});
