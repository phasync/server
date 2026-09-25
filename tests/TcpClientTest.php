<?php

use phasync\Net\TcpClient;
use phasync\Net\TcpServer;

test('TcpClient connects and exchanges data', function () {
    $response = phasync::run(function () {
        $server = new TcpServer('127.0.0.1:0');

        phasync::go(function () use ($server) {
            foreach ($server->accept() as $stream) {
                $data = fread(phasync::readable($stream), 65536);
                fwrite(phasync::writable($stream), "Echo: $data");
                fclose($stream);
                $server->close();
            }
        });

        $client = TcpClient::connect($server->getAddress());
        fwrite(phasync::writable($client), 'Hello');
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        return $response;
    });

    expect($response)->toBe('Echo: Hello');
});

test('TcpClient returns a plain non-blocking stream, not an AsyncStream-wrapped one', function () {
    $meta = phasync::run(function () {
        $server = new TcpServer('127.0.0.1:0');
        $client = TcpClient::connect($server->getAddress());
        $meta   = stream_get_meta_data($client);
        fclose($client);
        $server->close();

        return $meta;
    });

    expect($meta['wrapper_type'] ?? null)->not->toBe('user-space');
    expect($meta['blocked'])->toBeFalse();
});

test('TcpClient throws on refused connection', function () {
    phasync::run(function () {
        // Port 1 requires root and is almost certainly not listening
        expect(fn () => TcpClient::connect('127.0.0.1:1', timeout: 0.5))
            ->toThrow(RuntimeException::class);
    });
});

test('TcpClient connects to a Unix socket', function () {
    if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
        $this->markTestSkipped('Unix sockets not supported on Windows');
    }
    $socketPath = sys_get_temp_dir() . '/phasync_client_test_' . uniqid() . '.sock';

    $response = phasync::run(function () use ($socketPath) {
        $server = new TcpServer("unix://$socketPath");

        phasync::go(function () use ($server) {
            foreach ($server->accept() as $stream) {
                fwrite(phasync::writable($stream), 'Unix OK');
                fclose($stream);
                $server->close();
            }
        });

        $client   = TcpClient::connectUnix($socketPath);
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        return $response;
    });

    expect($response)->toBe('Unix OK');
});
