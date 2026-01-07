<?php

use phasync\Net\TcpServer;
use phasync\Util\WaitGroup;

// --- Helper to find a free port ---
function get_free_port(): int {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $addr, $port);
    socket_close($sock);
    return $port;
}

test('TcpServer accepts connections with foreach', function () {
    phasync::run(function () {
        $port = get_free_port();
        $server = new TcpServer("127.0.0.1:$port");

        expect($server->getAddresses())->toHaveCount(1);
        expect($server->isClosed())->toBeFalse();

        $connectionHandled = false;

        // Server coroutine - handle one connection then close
        phasync::go(function () use ($server, &$connectionHandled) {
            foreach ($server->accept() as $addr => $stream) {
                expect($addr)->toBeString();
                expect($stream)->toBeResource();

                // AsyncStream handles blocking automatically
                $data = fread($stream, 65536);
                $connectionHandled = true;  // Set before write (fwrite yields)
                fwrite($stream, "Got: $data");
                fclose($stream);

                $server->close();
                break;
            }
        });

        // Client - connect and exchange data
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        stream_set_blocking($client, false);
        fwrite(phasync::writable($client), "Hello");
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        expect($response)->toContain("Got: Hello");
        expect($connectionHandled)->toBeTrue();
    });
});

test('TcpServer can listen on multiple addresses', function () {
    phasync::run(function () {
        $port1 = get_free_port();
        $port2 = get_free_port();
        $server = new TcpServer(["127.0.0.1:$port1", "127.0.0.1:$port2"]);

        expect($server->getAddresses())->toHaveCount(2);

        $server->close();
    });
});

test('TcpServer accepts from multiple sockets', function () {
    phasync::run(function () {
        $port1 = get_free_port();
        $port2 = get_free_port();
        $server = new TcpServer(["127.0.0.1:$port1", "127.0.0.1:$port2"]);
        $connections = [];

        // Server coroutine
        phasync::go(function () use ($server, &$connections) {
            foreach ($server->accept() as $addr => $stream) {
                // AsyncStream handles blocking automatically
                $data = fread($stream, 65536);
                $connections[] = $data;  // Record before write (fwrite yields)
                fwrite($stream, "Got: $data");
                fclose($stream);

                if (count($connections) >= 2) {
                    $server->close();
                    break;
                }
            }
        });

        // Connect to both ports
        $client1 = stream_socket_client("tcp://127.0.0.1:$port1");
        $client2 = stream_socket_client("tcp://127.0.0.1:$port2");
        stream_set_blocking($client1, false);
        stream_set_blocking($client2, false);

        fwrite(phasync::writable($client1), "from-port-1");
        fwrite(phasync::writable($client2), "from-port-2");

        $resp1 = fread(phasync::readable($client1), 65536);
        $resp2 = fread(phasync::readable($client2), 65536);

        fclose($client1);
        fclose($client2);

        expect($resp1)->toContain("Got: from-port-1");
        expect($resp2)->toContain("Got: from-port-2");
        expect($connections)->toHaveCount(2);
    });
});

test('TcpServer throws on invalid address', function () {
    expect(fn() => @new TcpServer('invalid:99999'))
        ->toThrow(RuntimeException::class);
});

test('TcpServer handles concurrent connections', function () {
    phasync::run(function () {
        $port = get_free_port();
        $server = new TcpServer("127.0.0.1:$port");
        $wg = new WaitGroup();
        $count = 10; // Reduced from 50 for faster test

        // Server: Echo with delay to prove concurrency
        phasync::go(function () use ($server, $count) {
            $handled = 0;
            foreach ($server->accept() as $conn) {
                phasync::go(function() use ($conn) {
                    phasync::sleep(0.05);
                    fwrite($conn, "Done");
                    fclose($conn);
                });
                $handled++;
                if ($handled >= $count) {
                    $server->close();
                    break;
                }
            }
        });

        // Spawn clients
        $start = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            $wg->add();
            phasync::go(function() use ($port, $wg) {
                try {
                    $client = stream_socket_client("tcp://127.0.0.1:$port");
                    stream_set_blocking($client, false);
                    phasync::readable($client);
                    $res = fread($client, 1024);
                    expect($res)->toBe("Done");
                    fclose($client);
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->await();
        $duration = microtime(true) - $start;

        // Sequential would be 10 * 0.05s = 0.5s, parallel should be ~0.05s + overhead
        expect($duration)->toBeLessThan(0.3);
    });
});

test('TcpServer handles large payloads', function () {
    phasync::run(function () {
        $port = get_free_port();
        $server = new TcpServer("127.0.0.1:$port");

        // Server: Read 1MB and return hash
        phasync::go(function () use ($server) {
            foreach ($server->accept() as $conn) {
                $buffer = '';
                while (!feof($conn)) {
                    phasync::readable($conn);
                    $chunk = fread($conn, 65536);
                    if ($chunk === false || $chunk === '') break;
                    $buffer .= $chunk;
                }
                fwrite(phasync::writable($conn), md5($buffer));
                fclose($conn);
                $server->close();
                break;
            }
        });

        // Client: Send 1MB
        $client = stream_socket_client("tcp://127.0.0.1:$port");
        stream_set_blocking($client, false);

        $payload = str_repeat("X", 1024 * 1024);
        $hash = md5($payload);

        $offset = 0;
        $len = strlen($payload);
        while ($offset < $len) {
            phasync::writable($client);
            $written = fwrite($client, substr($payload, $offset, 65536));
            if ($written === false) break;
            $offset += $written;
        }

        stream_socket_shutdown($client, STREAM_SHUT_WR);

        phasync::readable($client);
        $response = fread($client, 1024);
        fclose($client);

        expect($response)->toBe($hash);
    });
});
