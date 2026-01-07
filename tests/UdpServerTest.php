<?php

use phasync\Net\UdpServer;

function get_free_udp_port(): int {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $addr, $port);
    socket_close($sock);
    return $port;
}

test('UdpServer echoes data', function () {
    phasync::run(function () {
        $port = get_free_udp_port();
        $server = new UdpServer("127.0.0.1:$port");

        expect($server->getAddresses())->toHaveCount(1);

        // Server coroutine
        phasync::go(function () use ($server) {
            foreach ($server->receive() as $peer => [$data, $socket]) {
                // Reply using the correct socket
                stream_socket_sendto($socket, "Echo: $data", 0, $peer);
                $server->close();
                break;
            }
        });

        // Client
        $client = stream_socket_client("udp://127.0.0.1:$port");
        fwrite($client, "Hello UDP");
        phasync::readable($client);
        $response = fread($client, 65536);
        fclose($client);

        expect($response)->toBe("Echo: Hello UDP");
    });
});

test('UdpServer handles multiple interfaces correctly', function () {
    phasync::run(function () {
        $port1 = get_free_udp_port();
        $port2 = get_free_udp_port();
        $server = new UdpServer(["127.0.0.1:$port1", "127.0.0.1:$port2"]);

        expect($server->getAddresses())->toHaveCount(2);

        $replies = 0;

        // Server coroutine
        phasync::go(function () use ($server, &$replies) {
            foreach ($server->receive() as $peer => [$data, $socket]) {
                stream_socket_sendto($socket, "Got: $data", 0, $peer);
                $replies++;

                if ($replies >= 2) {
                    $server->close();
                    break;
                }
            }
        });

        // Client 1 -> Port 1
        $c1 = stream_socket_client("udp://127.0.0.1:$port1");
        fwrite($c1, "Payload1");
        phasync::readable($c1);
        expect(fread($c1, 1024))->toBe("Got: Payload1");
        fclose($c1);

        // Client 2 -> Port 2
        $c2 = stream_socket_client("udp://127.0.0.1:$port2");
        fwrite($c2, "Payload2");
        phasync::readable($c2);
        expect(fread($c2, 1024))->toBe("Got: Payload2");
        fclose($c2);

        expect($replies)->toBe(2);
    });
});

test('UdpServer throws on invalid address', function () {
    expect(fn() => @new UdpServer('invalid:99999'))
        ->toThrow(RuntimeException::class);
});
